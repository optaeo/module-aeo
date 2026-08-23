<?php
declare(strict_types=1);

namespace Optaeo\Aeo\Model;

use Magento\Framework\DataObject;
use Optaeo\Aeo\Api\Data\WebhookConfigInterface;

class WebhookConfig extends DataObject implements WebhookConfigInterface
{
    /**
     * @inheritDoc
     */
    public function getCallbackUrl(): string
    {
        return (string) $this->getData(self::CALLBACK_URL);
    }

    /**
     * @inheritDoc
     */
    public function getSecretFingerprint(): string
    {
        return (string) $this->getData(self::SECRET_FINGERPRINT);
    }

    /**
     * @inheritDoc
     */
    public function getModuleVersion(): string
    {
        return (string) $this->getData(self::MODULE_VERSION);
    }
}
