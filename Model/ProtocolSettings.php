<?php
/**
 * The ONE reader for the OptAEO-provisioned protocol switches: whether the store
 * should serve /llms.txt and /agents.txt (+ /agents.md), and which IndexNow key
 * file (/<key>.txt) to host at the store root. Read by the protocol controllers and
 * the root router; written by ProtocolConfigManagement (PUT /V1/optaeo/protocol-config)
 * — pushed by the OptAEO connector whenever the merchant toggles a protocol in
 * OptAEO and self-healed on every sync, so what OptAEO shows and what the store
 * serves never disagree.
 *
 * Defaults (etc/config.xml): both files ON, no IndexNow key — the behaviour every
 * store had before these switches existed.
 */
declare(strict_types=1);

namespace Optaeo\Aeo\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

class ProtocolSettings
{
    public const XML_PATH_LLMS_TXT_ENABLED = 'optaeo/protocol/llms_txt_enabled';
    public const XML_PATH_AGENTS_TXT_ENABLED = 'optaeo/protocol/agents_txt_enabled';
    public const XML_PATH_INDEXNOW_KEY = 'optaeo/protocol/indexnow_key';

    /** IndexNow key contract: 8–128 chars, a-z A-Z 0-9 and dash. */
    public const INDEXNOW_KEY_PATTERN = '/^[A-Za-z0-9-]{8,128}$/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isLlmsTxtEnabled(): bool
    {
        return $this->flag(self::XML_PATH_LLMS_TXT_ENABLED);
    }

    public function isAgentsTxtEnabled(): bool
    {
        return $this->flag(self::XML_PATH_AGENTS_TXT_ENABLED);
    }

    /** The IndexNow key to host, or '' when none is provisioned. */
    public function getIndexNowKey(): string
    {
        $key = trim((string) $this->scopeConfig->getValue(self::XML_PATH_INDEXNOW_KEY));
        return preg_match(self::INDEXNOW_KEY_PATTERN, $key) === 1 ? $key : '';
    }

    /**
     * Unset (null) means "never provisioned" and keeps the historical behaviour
     * (served); only an explicit 0/false disables.
     */
    private function flag(string $path): bool
    {
        $value = $this->scopeConfig->getValue($path);
        if ($value === null || $value === '') {
            return true;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }
}
