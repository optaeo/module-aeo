<?php
/**
 * The process-level facilities the dispatcher relies on to decide WHEN a callback
 * is sent — isolated behind an interface so unit tests can drive the shutdown
 * flush deterministically without a real SAPI.
 */
declare(strict_types=1);

namespace Optaeo\Aeo\Model\Webhook;

interface RuntimeInterface
{
    /**
     * True when the response can be handed to the web server BEFORE the script
     * ends (php-fpm's fastcgi_finish_request, LiteSpeed's equivalent). This is what
     * makes a deferred delivery invisible to the merchant's admin save.
     */
    public function canFinishRequestEarly(): bool;

    /** True under the CLI SAPI (bin/magento, cron, consumers) — nobody is waiting on a response. */
    public function isCli(): bool;

    /** Register a callable to run at script shutdown. */
    public function onShutdown(callable $callback): void;

    /**
     * Flush the response to the web server and close the client connection, and
     * release the PHP session lock so a concurrent admin request is never held up
     * by the delivery that follows. No-op when unavailable.
     */
    public function finishRequest(): void;

    /** Monotonic-ish wall clock in milliseconds (for the flush budget). */
    public function nowMs(): int;
}
