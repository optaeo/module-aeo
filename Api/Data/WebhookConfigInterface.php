<?php
/**
 * Read-back shape for the observer callback config — what the store ACTUALLY holds
 * after a provisioning push, so the OptAEO connector can verify the push landed
 * (never trusting the PUT's `true` alone) and can tell an outdated module apart
 * from a broken one. The secret itself is never returned; only a fingerprint.
 */
declare(strict_types=1);

namespace Optaeo\Aeo\Api\Data;

interface WebhookConfigInterface
{
    public const CALLBACK_URL = 'callback_url';
    public const SECRET_FINGERPRINT = 'secret_fingerprint';
    public const MODULE_VERSION = 'module_version';

    /**
     * @return string The configured receiver URL ('' when the observer is disabled).
     */
    public function getCallbackUrl(): string;

    /**
     * @return string First 12 hex chars of SHA-256(secret); '' when no secret is set.
     */
    public function getSecretFingerprint(): string;

    /**
     * @return string Installed optaeo/module-aeo version (from Composer), or "unknown".
     */
    public function getModuleVersion(): string;
}
