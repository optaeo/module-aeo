<?php
/**
 * Saves the OptAEO observer callback config (URL + shared secret) into
 * core_config_data and flushes the config cache so the very next product save
 * observes the new values. The secret is stored obscured (encrypted backend not
 * required for a derived, revocable-by-rotation value; it never grants store
 * access — it only signs callbacks TO OptAEO).
 *
 * get() reads the values back through the config layer after save() has cleaned
 * the config cache type, so the connector's PUT-then-GET (two REST requests) sees
 * what the store actually persisted rather than trusting the PUT's `true`.
 */
declare(strict_types=1);

namespace Optaeo\Aeo\Model;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Optaeo\Aeo\Api\Data\WebhookConfigInterface;
use Optaeo\Aeo\Api\Data\WebhookConfigInterfaceFactory;
use Optaeo\Aeo\Api\WebhookConfigManagementInterface;

class WebhookConfigManagement implements WebhookConfigManagementInterface
{
    public const XML_PATH_CALLBACK_URL = 'optaeo/webhook/callback_url';
    public const XML_PATH_SECRET = 'optaeo/webhook/secret';

    public function __construct(
        private readonly WriterInterface $configWriter,
        private readonly TypeListInterface $cacheTypeList,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WebhookConfigInterfaceFactory $configFactory,
        private readonly ModuleVersion $moduleVersion
    ) {
    }

    public function save(string $callbackUrl, string $secret): bool
    {
        $callbackUrl = trim($callbackUrl);
        $secret = trim($secret);
        // Refuse garbage: an empty/na-URL config would turn the observer into a
        // per-save error source. Empty BOTH is a legitimate "disable" push.
        if ($callbackUrl !== '' && !preg_match('#^https?://#i', $callbackUrl)) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('callbackUrl must be an absolute http(s) URL or empty to disable.')
            );
        }
        $this->configWriter->save(self::XML_PATH_CALLBACK_URL, $callbackUrl);
        $this->configWriter->save(self::XML_PATH_SECRET, $secret);
        // Flush config cache so the next request's observer reads the new values —
        // same post-write discipline as the module's EAV data patches.
        $this->cacheTypeList->cleanType('config');
        return true;
    }

    public function get(): WebhookConfigInterface
    {
        // Served by a separate REST request from the PUT, so this reflects
        // core_config_data as persisted (save() cleaned the config cache type).
        $callbackUrl = trim((string) $this->scopeConfig->getValue(self::XML_PATH_CALLBACK_URL));
        $secret = trim((string) $this->scopeConfig->getValue(self::XML_PATH_SECRET));
        return $this->configFactory->create(['data' => [
            WebhookConfigInterface::CALLBACK_URL => $callbackUrl,
            WebhookConfigInterface::SECRET_FINGERPRINT => $secret === '' ? '' : substr(hash('sha256', $secret), 0, 12),
            WebhookConfigInterface::MODULE_VERSION => $this->moduleVersion->get(),
        ]]);
    }
}
