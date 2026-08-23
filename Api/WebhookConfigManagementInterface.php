<?php
/**
 * Provisioning endpoint for the OptAEO product-save observer. The OptAEO connector
 * calls PUT /V1/optaeo/webhook-config after every sync (registerWebhooks) to push
 * the callback URL + per-merchant shared secret into core_config_data — so the
 * merchant never hand-configures anything and a wiped config self-heals on the
 * next sync — and GET /V1/optaeo/webhook-config to READ BACK what the store holds
 * (the push is only counted as provisioned once the read-back agrees). Authorized
 * by the connector's OAuth1 Integration token (ACL Optaeo_Aeo::webhooks).
 */
declare(strict_types=1);

namespace Optaeo\Aeo\Api;

use Optaeo\Aeo\Api\Data\WebhookConfigInterface;

interface WebhookConfigManagementInterface
{
    /**
     * Save the observer's callback config. Overwrites both values (idempotent).
     *
     * @param string $callbackUrl Absolute OptAEO receiver URL.
     * @param string $secret Per-merchant signing key for timestamped HMAC deliveries.
     * @return bool true when saved.
     */
    public function save(string $callbackUrl, string $secret): bool;

    /**
     * Read back the observer's callback config as the store holds it right now
     * (secret returned as a fingerprint only), plus the installed module version.
     *
     * @return \Optaeo\Aeo\Api\Data\WebhookConfigInterface
     */
    public function get(): WebhookConfigInterface;
}
