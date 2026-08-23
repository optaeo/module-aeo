<?php
/**
 * Delivers product-save callbacks to OptAEO WITHOUT slowing the merchant's save
 * and WITHOUT ever pretending a callback was delivered when it was not.
 *
 * Timing model (chosen for robustness on ordinary shared hosting — no message
 * queue, no cron consumer, no extra infrastructure):
 *   - php-fpm / LiteSpeed (the common case): the observer only queues; the queue
 *     is flushed in a shutdown function AFTER fastcgi_finish_request() has handed
 *     the response to the web server — the merchant sees zero added latency and
 *     the delivery still waits for OptAEO's answer.
 *   - CLI (bin/magento, cron, import): queued and flushed at process exit — nobody
 *     is waiting on a response, and a long import is not slowed per product.
 *   - Anything else (mod_php, CGI): delivered inline with a tighter bound; the save
 *     completes a little later but the callback is real.
 *
 * Every attempt is logged honestly: successes at debug (quiet by default), every
 * failure at warning with the reason and what OptAEO does about it (the next full
 * sync reconciles). Nothing is swallowed. Bounded everywhere: per-request cap,
 * per-attempt timeouts, an overall flush budget, and a circuit that stops the
 * flush the moment OptAEO proves unreachable.
 */
declare(strict_types=1);

namespace Optaeo\Aeo\Model\Webhook;

use Optaeo\Aeo\Model\ModuleVersion;
use Psr\Log\LoggerInterface;

class CallbackDispatcher
{
    /** Distinct SKUs pushed per PHP request; beyond this the full sync reconciles. */
    public const MAX_EVENTS_PER_REQUEST = 25;
    public const CONNECT_TIMEOUT_MS = 3000;
    /** Deferred (post-response) delivery may wait for OptAEO's re-sync + re-score. */
    public const DEFERRED_TOTAL_TIMEOUT_MS = 15000;
    /** Inline delivery (no early-finish available) keeps ONE callback bounded. */
    public const INLINE_TOTAL_TIMEOUT_MS = 8000;
    /**
     * Total delivery time one request may spend INLINE, across every callback in it.
     * The merchant is waiting on this path, so the whole request — not each event —
     * is what must be bounded: a mass action saving 40 products under mod_php must
     * never pay 40 × the per-event bound. Each attempt is given whatever is left of
     * this budget (never more than the per-event bound), and once less than
     * INLINE_MIN_ATTEMPT_MS remains the rest are dropped with an honest warning.
     */
    public const INLINE_BUDGET_MS = 8000;
    /** Below this there is no point starting another inline attempt. */
    public const INLINE_MIN_ATTEMPT_MS = 1000;
    /** Wall-clock budget for one post-response flush. */
    public const FLUSH_BUDGET_MS = 45000;

    /** @var array<string, CallbackEvent> */
    private array $queue = [];
    private int $overflow = 0;
    private bool $shutdownRegistered = false;
    private ?string $userAgent = null;

    /**
     * Inline path accounting (mod_php / CGI).
     *
     * @var array<string, true> SKU key => already delivered in this request
     */
    private array $inlineDone = [];
    private int $inlineSpentMs = 0;
    private bool $inlineUnreachable = false;
    /** @var array<string, string[]> reason => SKUs dropped inline, reported once at shutdown */
    private array $inlineDropped = [];

    public function __construct(
        private readonly HttpTransportInterface $transport,
        private readonly SignedRequestBuilder $requestBuilder,
        private readonly RuntimeInterface $runtime,
        private readonly ModuleVersion $moduleVersion,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Accept one product-save event. Deferred whenever the response can be finished
     * early (php-fpm) or nobody is waiting (CLI); otherwise delivered inline, bounded.
     */
    public function enqueue(CallbackEvent $event): void
    {
        $this->registerShutdown();
        if (!$this->runtime->canFinishRequestEarly() && !$this->runtime->isCli()) {
            $this->deliverInline($event);
            return;
        }
        $key = $event->key();
        if (!isset($this->queue[$key]) && count($this->queue) >= self::MAX_EVENTS_PER_REQUEST) {
            $this->overflow++;
            return;
        }
        $this->queue[$key] = $event; // last save of a SKU wins; one delivery per SKU
    }

    /**
     * mod_php / CGI: there is no way to answer the merchant before delivering, so the
     * callback happens during the save — under the SAME protections the queued path
     * has, because this is the one path where a cost lands on the merchant:
     *   dedupe   — one delivery per SKU per request;
     *   cap      — at most MAX_EVENTS_PER_REQUEST distinct SKUs;
     *   budget   — INLINE_BUDGET_MS of delivery time for the whole request, with each
     *              attempt bounded by whatever is left of it (hard aggregate bound);
     *   circuit  — the first "OptAEO unreachable" stops every later attempt.
     * Everything dropped is reported (once per reason) at shutdown, never silently.
     */
    private function deliverInline(CallbackEvent $event): void
    {
        $key = $event->key();
        if (isset($this->inlineDone[$key])) {
            return; // same SKU saved twice in one request — one delivery is enough
        }
        if ($this->inlineUnreachable) {
            $this->dropInline('OptAEO was unreachable earlier in this request', $event);
            return;
        }
        if (count($this->inlineDone) >= self::MAX_EVENTS_PER_REQUEST) {
            $this->dropInline(
                sprintf('the per-request cap of %d callbacks was reached (bulk change)', self::MAX_EVENTS_PER_REQUEST),
                $event
            );
            return;
        }
        $remainingMs = self::INLINE_BUDGET_MS - $this->inlineSpentMs;
        if ($remainingMs < self::INLINE_MIN_ATTEMPT_MS) {
            $this->dropInline(
                sprintf('this request already spent its %d ms callback budget', self::INLINE_BUDGET_MS),
                $event
            );
            return;
        }

        $startedAt = $this->runtime->nowMs();
        $result = $this->deliver($event, min(self::INLINE_TOTAL_TIMEOUT_MS, $remainingMs));
        $this->inlineSpentMs += max(0, $this->runtime->nowMs() - $startedAt);
        $this->inlineDone[$key] = true;
        if ($result->isConnectFailure()) {
            $this->inlineUnreachable = true;
        }
    }

    private function dropInline(string $reason, CallbackEvent $event): void
    {
        $this->inlineDropped[$reason][] = $event->sku;
    }

    private function registerShutdown(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }
        $this->shutdownRegistered = true;
        $this->runtime->onShutdown([$this, 'flush']);
    }

    /**
     * Shutdown flush: finish the request first (the merchant's save returns), then
     * deliver sequentially within the budget. Public because it is the registered
     * shutdown callable; safe to call more than once (idempotent on an empty queue).
     */
    public function flush(): void
    {
        $this->reportInlineDrops();
        if ($this->queue === [] && $this->overflow === 0) {
            return;
        }
        $events = array_values($this->queue);
        $this->queue = [];
        $overflow = $this->overflow;
        $this->overflow = 0;

        if ($events !== []) {
            $this->runtime->finishRequest();
        }

        $started = $this->runtime->nowMs();
        $delivered = 0;
        foreach ($events as $index => $event) {
            $remaining = count($events) - $index;
            $spent = $this->runtime->nowMs() - $started;
            if ($spent >= self::FLUSH_BUDGET_MS) {
                $this->logger->warning(sprintf(
                    '[optaeo] product-save callbacks: flush budget (%d ms) exhausted after %d delivered; '
                    . '%d not attempted (%s). Those products reconcile on the next OptAEO sync.',
                    self::FLUSH_BUDGET_MS,
                    $delivered,
                    $remaining,
                    $this->skuList(array_slice($events, $index))
                ));
                break;
            }
            $result = $this->deliver($event, self::DEFERRED_TOTAL_TIMEOUT_MS);
            if ($result->isDelivered()) {
                $delivered++;
            } elseif ($result->isConnectFailure() && $remaining > 1) {
                // OptAEO is unreachable: do not spend one connect timeout per event.
                $this->logger->warning(sprintf(
                    '[optaeo] product-save callbacks: OptAEO unreachable — %d further callback(s) not attempted (%s). '
                    . 'Those products reconcile on the next OptAEO sync.',
                    $remaining - 1,
                    $this->skuList(array_slice($events, $index + 1))
                ));
                break;
            }
        }
        if ($overflow > 0) {
            $this->logger->warning(sprintf(
                '[optaeo] product-save callbacks: %d save(s) in this request exceeded the per-request cap of %d '
                . 'and were not pushed (bulk change). Those products reconcile on the next OptAEO sync.',
                $overflow,
                self::MAX_EVENTS_PER_REQUEST
            ));
        }
    }

    /**
     * One signed delivery, waited on and logged. Never throws.
     */
    private function deliver(CallbackEvent $event, int $totalTimeoutMs): TransportResult
    {
        try {
            $request = $this->requestBuilder->build(
                $event,
                time(),
                $this->requestBuilder->newEventId(),
                $this->userAgent()
            );
            $result = $this->transport->post($request, self::CONNECT_TIMEOUT_MS, $totalTimeoutMs);
        } catch (\Throwable $e) {
            $result = new TransportResult(null, '', false, get_class($e) . ': ' . $e->getMessage(), 0, 0);
        }
        $this->report($event, $result);
        return $result;
    }

    /** Honest per-delivery log line: what happened, why, and what OptAEO does next. */
    private function report(CallbackEvent $event, TransportResult $result): void
    {
        $sku = $event->sku;
        if ($result->isDelivered()) {
            $answer = json_decode($result->body, true);
            $answer = is_array($answer) ? $answer : [];
            if (($answer['duplicate'] ?? false) === true) {
                $this->logger->debug(sprintf(
                    '[optaeo] product-save callback sku=%s: OptAEO already had this event (duplicate, %d ms).',
                    $sku,
                    $result->elapsedMs
                ));
                return;
            }
            if (($answer['rate_limited'] ?? false) === true) {
                $this->logger->info(sprintf(
                    '[optaeo] product-save callback sku=%s: accepted by OptAEO but rate-limited (bulk change); '
                    . 'the product reconciles on the next OptAEO sync (%d ms).',
                    $sku,
                    $result->elapsedMs
                ));
                return;
            }
            if (($answer['ok'] ?? null) === false || ($answer['handled'] ?? null) === false) {
                $this->logger->warning(sprintf(
                    '[optaeo] product-save callback sku=%s reached OptAEO (HTTP %d) but was NOT processed: %s. '
                    . 'The product reconciles on the next OptAEO sync. If this persists, re-run a sync in OptAEO '
                    . '(Settings → Store → Sync now) so the store connection and callback config are re-provisioned.',
                    $sku,
                    $result->httpStatus,
                    (string) ($answer['error'] ?? 'no reason given')
                ));
                return;
            }
            $this->logger->debug(sprintf(
                '[optaeo] product-save callback sku=%s delivered (HTTP %d, %s, %d ms).',
                $sku,
                $result->httpStatus,
                ($answer['rescored'] ?? null) === true ? 're-scored' : 'accepted',
                $result->elapsedMs
            ));
            return;
        }

        if ($result->httpStatus !== null) {
            $hint = $result->httpStatus === 401
                ? ' The signature was rejected — the module\'s webhook secret no longer matches OptAEO. '
                    . 'Re-run a sync in OptAEO (Settings → Store → Sync now) to re-provision it.'
                : ' The product reconciles on the next OptAEO sync.';
            $this->logger->warning(sprintf(
                '[optaeo] product-save callback sku=%s rejected by OptAEO (HTTP %d%s, %d ms).%s',
                $sku,
                $result->httpStatus,
                $result->body !== '' ? ': ' . substr(trim($result->body), 0, 200) : '',
                $result->elapsedMs,
                $hint
            ));
            return;
        }

        if ($result->requestSent) {
            $this->logger->warning(sprintf(
                '[optaeo] product-save callback sku=%s was sent but OptAEO did not answer within %d ms (%s). '
                . 'OptAEO may still have processed it; the product reconciles on the next OptAEO sync either way.',
                $sku,
                $result->elapsedMs,
                (string) $result->error
            ));
            return;
        }

        $this->logger->warning(sprintf(
            '[optaeo] product-save callback sku=%s NOT delivered — OptAEO unreachable: %s (%d ms). '
            . 'The product reconciles on the next OptAEO sync.',
            $sku,
            (string) $result->error,
            $result->elapsedMs
        ));
    }

    /** One honest line per reason for whatever the inline path could not deliver. */
    private function reportInlineDrops(): void
    {
        if ($this->inlineDropped === []) {
            return;
        }
        $dropped = $this->inlineDropped;
        $this->inlineDropped = [];
        foreach ($dropped as $reason => $skus) {
            $this->logger->warning(sprintf(
                '[optaeo] product-save callbacks: %d callback(s) not delivered because %s (%s). '
                . 'Those products reconcile on the next OptAEO sync.',
                count($skus),
                $reason,
                $this->skuListFromSkus($skus)
            ));
        }
    }

    private function userAgent(): string
    {
        if ($this->userAgent === null) {
            $this->userAgent = 'OptAEO-Magento/' . $this->moduleVersion->get();
        }
        return $this->userAgent;
    }

    /** @param CallbackEvent[] $events */
    private function skuList(array $events): string
    {
        return $this->skuListFromSkus(array_map(static fn(CallbackEvent $e): string => $e->sku, $events));
    }

    /** @param string[] $skus */
    private function skuListFromSkus(array $skus): string
    {
        if (count($skus) > 10) {
            return implode(', ', array_slice($skus, 0, 10)) . sprintf(', … +%d more', count($skus) - 10);
        }
        return implode(', ', $skus);
    }
}
