<?php
/**
 * catalog_product_save_after → near-real-time OptAEO re-sync + re-score.
 *
 * Magento core has no product webhooks, so a merchant's edit sat stale in OptAEO
 * until the next scheduled full sync. This observer POSTs { sku, store_host } to
 * OptAEO's /api/webhooks/magento, which re-pulls THAT product from the store and
 * re-scores it — the same near-real-time loop Shopify's products/update webhook
 * already gives Shopify merchants. Every delivery has a cryptographic event ID,
 * timestamp, and HMAC signature so OptAEO can reject stale/replayed deliveries.
 *
 * NON-BLOCKING BY DESIGN: this fires synchronously inside the merchant's product
 * save, so it must never slow or fail the save. Fire-and-forget stream socket —
 * 1s connect timeout, write the request, close WITHOUT reading the response; every
 * throwable is swallowed. OptAEO down/unreachable ⇒ the save still succeeds and the
 * score simply updates on the next full sync (no worse than before this observer).
 *
 * Config (core_config_data, provisioned by the connector via
 * PUT /V1/optaeo/webhook-config): optaeo/webhook/callback_url + optaeo/webhook/secret.
 * Empty callback_url ⇒ instant no-op (the shipped-disabled default).
 */
declare(strict_types=1);

namespace Optaeo\Aeo\Observer;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;
use Optaeo\Aeo\Model\WebhookConfigManagement;
use Psr\Log\LoggerInterface;

class ProductSaveObserver implements ObserverInterface
{
    private const CONNECT_TIMEOUT_SECONDS = 1.0;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $callbackUrl = trim((string) $this->scopeConfig->getValue(WebhookConfigManagement::XML_PATH_CALLBACK_URL));
            $secret = trim((string) $this->scopeConfig->getValue(WebhookConfigManagement::XML_PATH_SECRET));
            if ($callbackUrl === '' || $secret === '') {
                return; // not provisioned — shipped-disabled default, zero cost
            }

            $product = $observer->getEvent()->getData('product');
            $sku = $product ? trim((string) $product->getSku()) : '';
            if ($sku === '') {
                return;
            }

            // The store's base URL host is the merchant key OptAEO resolves with
            // (merchants.shop_domain via normalizeStoreHost).
            $storeHost = (string) parse_url(
                $this->storeManager->getStore()->getBaseUrl(),
                PHP_URL_HOST
            );
            if ($storeHost === '') {
                return;
            }

            $this->fireAndForget($callbackUrl, $secret, $sku, $storeHost);
        } catch (\Throwable $e) {
            // NEVER let the callback path touch the merchant's save. Debug-level:
            // this is an optional signal, not an error condition for the store.
            $this->logger->debug('[optaeo] product-save callback skipped: ' . $e->getMessage());
        }
    }

    /**
     * Write the HTTP request to a raw stream socket and close WITHOUT reading the
     * response. Worst case (black-holed host) costs the 1s connect timeout; a
     * refused connection fails in milliseconds; the normal reachable case adds
     * single-digit milliseconds to the save.
     */
    private function fireAndForget(string $callbackUrl, string $secret, string $sku, string $storeHost): void
    {
        $parts = parse_url($callbackUrl);
        if (!is_array($parts) || empty($parts['host'])) {
            return;
        }
        $tls = ($parts['scheme'] ?? 'https') === 'https';
        $port = (int) ($parts['port'] ?? ($tls ? 443 : 80));
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $target = ($tls ? 'ssl://' : 'tcp://') . $parts['host'] . ':' . $port;

        $body = (string) json_encode(['sku' => $sku, 'store_host' => $storeHost], JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        // 128 bits of entropy makes this a delivery identifier, not merely a
        // product/version fingerprint. Each save is intentionally unique.
        $eventId = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', $timestamp . '.' . $eventId . '.' . $body, $secret);
        $request = "POST {$path} HTTP/1.1\r\n"
            . "Host: {$parts['host']}\r\n"
            . "Content-Type: application/json\r\n"
            . "X-OptAEO-Webhook-Timestamp: {$timestamp}\r\n"
            . "X-OptAEO-Webhook-Id: {$eventId}\r\n"
            . "X-OptAEO-Webhook-Signature: sha256={$signature}\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $body;

        $errno = 0;
        $errstr = '';
        // @ suppression is deliberate: an unreachable OptAEO must be silent here.
        $socket = @stream_socket_client($target, $errno, $errstr, self::CONNECT_TIMEOUT_SECONDS);
        if ($socket === false) {
            return;
        }
        @stream_set_timeout($socket, 0, 200_000); // 200ms write budget
        @fwrite($socket, $request);
        // Bounded grace before close (max 250ms): some HTTP servers discard a
        // request whose client has already disconnected before they accept/route it
        // (observed live on the Vite dev server during cold route compile; proxies
        // can do the same). Waiting for the first response byte — or 250ms,
        // whichever comes first — keeps the connection alive through accept without
        // waiting for OptAEO's actual processing. Never reads the body.
        $read = [$socket];
        $write = null;
        $except = null;
        @stream_select($read, $write, $except, 0, 250_000);
        @fclose($socket); // do not read the response — OptAEO does the work async of us
    }
}
