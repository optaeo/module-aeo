<?php
declare(strict_types=1);

namespace Optaeo\Aeo\Model;

use Magento\Framework\DataObject;
use Optaeo\Aeo\Api\Data\ShippingConfigInterface;

class ShippingConfig extends DataObject implements ShippingConfigInterface
{
    /**
     * @inheritDoc
     */
    public function getHasFreeShipping(): bool
    {
        return (bool) $this->getData('has_free_shipping');
    }

    /**
     * @inheritDoc
     */
    public function setHasFreeShipping(bool $v): self
    {
        return $this->setData('has_free_shipping', $v);
    }

    /**
     * @inheritDoc
     */
    public function getFreeShippingThreshold(): ?float
    {
        $v = $this->getData('free_shipping_threshold');
        return $v === null ? null : (float) $v;
    }

    /**
     * @inheritDoc
     */
    public function setFreeShippingThreshold(?float $v): self
    {
        return $this->setData('free_shipping_threshold', $v);
    }

    /**
     * @inheritDoc
     */
    public function getFreeShippingThresholdCurrency(): ?string
    {
        $v = $this->getData('free_shipping_threshold_currency');
        return $v === null ? null : (string) $v;
    }

    /**
     * @inheritDoc
     */
    public function setFreeShippingThresholdCurrency(?string $v): self
    {
        return $this->setData('free_shipping_threshold_currency', $v);
    }

    /**
     * @inheritDoc
     */
    public function getFreeShippingSource(): ?string
    {
        $v = $this->getData('free_shipping_source');
        return $v === null ? null : (string) $v;
    }

    /**
     * @inheritDoc
     */
    public function setFreeShippingSource(?string $v): self
    {
        return $this->setData('free_shipping_source', $v);
    }

    /**
     * @inheritDoc
     */
    public function getActiveCarriers(): array
    {
        return (array) $this->getData('active_carriers');
    }

    /**
     * @inheritDoc
     */
    public function setActiveCarriers(array $v): self
    {
        return $this->setData('active_carriers', $v);
    }

    /**
     * @inheritDoc
     */
    public function getCarrierCount(): int
    {
        return (int) $this->getData('carrier_count');
    }

    /**
     * @inheritDoc
     */
    public function setCarrierCount(int $v): self
    {
        return $this->setData('carrier_count', $v);
    }

    /**
     * @inheritDoc
     */
    public function getFlatRatePrice(): ?float
    {
        $v = $this->getData('flat_rate_price');
        return $v === null ? null : (float) $v;
    }

    /**
     * @inheritDoc
     */
    public function setFlatRatePrice(?float $v): self
    {
        return $this->setData('flat_rate_price', $v);
    }

    /**
     * @inheritDoc
     */
    public function getTablerateCondition(): ?string
    {
        $v = $this->getData('tablerate_condition');
        return $v === null ? null : (string) $v;
    }

    /**
     * @inheritDoc
     */
    public function setTablerateCondition(?string $v): self
    {
        return $this->setData('tablerate_condition', $v);
    }

    /**
     * @inheritDoc
     */
    public function getTableratePriceMin(): ?float
    {
        $v = $this->getData('tablerate_price_min');
        return $v === null ? null : (float) $v;
    }

    /**
     * @inheritDoc
     */
    public function setTableratePriceMin(?float $v): self
    {
        return $this->setData('tablerate_price_min', $v);
    }

    /**
     * @inheritDoc
     */
    public function getTableratePriceMax(): ?float
    {
        $v = $this->getData('tablerate_price_max');
        return $v === null ? null : (float) $v;
    }

    /**
     * @inheritDoc
     */
    public function setTableratePriceMax(?float $v): self
    {
        return $this->setData('tablerate_price_max', $v);
    }

    /**
     * @inheritDoc
     */
    public function getOriginCountry(): ?string
    {
        $v = $this->getData('origin_country');
        return $v === null ? null : (string) $v;
    }

    /**
     * @inheritDoc
     */
    public function setOriginCountry(?string $v): self
    {
        return $this->setData('origin_country', $v);
    }

    /**
     * @inheritDoc
     */
    public function getAllowedCountries(): array
    {
        return (array) $this->getData('allowed_countries');
    }

    /**
     * @inheritDoc
     */
    public function setAllowedCountries(array $v): self
    {
        return $this->setData('allowed_countries', $v);
    }

    /**
     * @inheritDoc
     */
    public function getTransitDaysMin(): ?int
    {
        $v = $this->getData('transit_days_min');
        return $v === null ? null : (int) $v;
    }

    /**
     * @inheritDoc
     */
    public function setTransitDaysMin(?int $v): self
    {
        return $this->setData('transit_days_min', $v);
    }

    /**
     * @inheritDoc
     */
    public function getTransitDaysMax(): ?int
    {
        $v = $this->getData('transit_days_max');
        return $v === null ? null : (int) $v;
    }

    /**
     * @inheritDoc
     */
    public function setTransitDaysMax(?int $v): self
    {
        return $this->setData('transit_days_max', $v);
    }

    /**
     * @inheritDoc
     */
    public function getCurrency(): string
    {
        return (string) $this->getData('currency');
    }

    /**
     * @inheritDoc
     */
    public function setCurrency(string $v): self
    {
        return $this->setData('currency', $v);
    }

    /**
     * @inheritDoc
     */
    public function getWebsiteId(): int
    {
        return (int) $this->getData('website_id');
    }

    /**
     * @inheritDoc
     */
    public function setWebsiteId(int $v): self
    {
        return $this->setData('website_id', $v);
    }
}
