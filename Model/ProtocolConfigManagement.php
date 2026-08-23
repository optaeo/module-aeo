<?php
/**
 * Persists the protocol switches (see ProtocolSettings) into core_config_data.
 * Idempotent and cheap on the no-change path — the connector re-pushes on every
 * sync as a self-heal, so an unchanged push must not write or purge anything.
 * When a switch actually flips, the config cache is cleaned so the next request
 * sees it, and the full-page cache is invalidated so a switch-OFF is real even
 * behind a page cache.
 */
declare(strict_types=1);

namespace Optaeo\Aeo\Model;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Exception\LocalizedException;
use Optaeo\Aeo\Api\ProtocolConfigManagementInterface;

class ProtocolConfigManagement implements ProtocolConfigManagementInterface
{
    public function __construct(
        private readonly WriterInterface $configWriter,
        private readonly TypeListInterface $cacheTypeList,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function save(bool $llmsTxtEnabled, bool $agentsTxtEnabled, string $indexNowKey = ''): bool
    {
        $indexNowKey = trim($indexNowKey);
        if ($indexNowKey !== '' && preg_match(ProtocolSettings::INDEXNOW_KEY_PATTERN, $indexNowKey) !== 1) {
            throw new LocalizedException(
                __('indexNowKey must be 8-128 characters of A-Z, a-z, 0-9 or "-", or empty to host none.')
            );
        }
        $desired = [
            ProtocolSettings::XML_PATH_LLMS_TXT_ENABLED => $llmsTxtEnabled ? '1' : '0',
            ProtocolSettings::XML_PATH_AGENTS_TXT_ENABLED => $agentsTxtEnabled ? '1' : '0',
            ProtocolSettings::XML_PATH_INDEXNOW_KEY => $indexNowKey,
        ];
        $changed = [];
        foreach ($desired as $path => $value) {
            $current = trim((string) ($this->scopeConfig->getValue($path) ?? ''));
            if ($current !== $value) {
                $this->configWriter->save($path, $value);
                $changed[] = $path;
            }
        }
        if ($changed === []) {
            return true; // already in the requested state — nothing written, no cache churn
        }
        $this->cacheTypeList->cleanType('config');
        if ($changed !== [ProtocolSettings::XML_PATH_INDEXNOW_KEY]) {
            // A served-file switch flipped: a page cache in front of the store may
            // still hold /llms.txt or /agents.txt — invalidate so the change is real.
            $this->cacheTypeList->cleanType('full_page');
        }
        return true;
    }
}
