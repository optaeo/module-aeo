<?php
declare(strict_types=1);

namespace Optaeo\Aeo\Model;

use Magento\Framework\DataObject;
use Optaeo\Aeo\Api\Data\ShippingCarrierInterface;

class ShippingCarrier extends DataObject implements ShippingCarrierInterface
{
    /**
     * @inheritDoc
     */
    public function getCode(): ?string
    {
        $v = $this->getData(self::CODE);
        return $v === null ? null : (string) $v;
    }

    /**
     * @inheritDoc
     */
    public function setCode(?string $code): self
    {
        return $this->setData(self::CODE, $code);
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): ?string
    {
        $v = $this->getData(self::TITLE);
        return $v === null ? null : (string) $v;
    }

    /**
     * @inheritDoc
     */
    public function setTitle(?string $title): self
    {
        return $this->setData(self::TITLE, $title);
    }
}
