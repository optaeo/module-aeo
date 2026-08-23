<?php
/**
 * catalog_product_save_after → near-real-time OptAEO re-sync + re-score.
 *
 * Magento core has no product webhooks, so a merchant's edit sat stale in OptAEO
 * until the next full sync. This observer hands { sku, store_host } to the callback
 * dispatcher, which POSTs it (HMAC-signed) to OptAEO's /api/webhooks/magento; OptAEO
 * re-pulls THAT product from the store and re-scores it — the same near-real-time
 * loop Shopify's products/update webhook gives Shopify merchants.
 *
 * NON-BLOCKING BY DESIGN, DELIVERED BY DESIGN: the observer itself only captures
 * strings and returns. Delivery happens after the response has been handed to the
 * web server (php-fpm), at process exit (CLI), or inline but bounded where neither
 * is possible — see Model/Webhook/CallbackDispatcher. Every outcome is logged; a
 * failed delivery is a warning in the module log, never a failed save.
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
use Optaeo\Aeo\Model\Webhook\CallbackDispatcher;
use Optaeo\Aeo\Model\Webhook\CallbackEvent;
use Optaeo\Aeo\Model\WebhookConfigManagement;
use Psr\Log\LoggerInterface;

class ProductSaveObserver implements ObserverInterface
{
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly CallbackDispatcher $dispatcher,
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

            $this->dispatcher->enqueue(new CallbackEvent($callbackUrl, $secret, $sku, $storeHost));
        } catch (\Throwable $e) {
            // NEVER let the callback path touch the merchant's save — but say so.
            $this->logger->warning('[optaeo] product-save callback could not be queued: ' . $e->getMessage());
        }
    }
}
