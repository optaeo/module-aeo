<?php
declare(strict_types=1);

namespace Optaeo\Aeo\Api\Data;

/**
 * Store-level shipping configuration returned by the OptAEO shipping API — core
 * Magento exposes no shipping config over REST, so this is the gap-fill the
 * connector's shipping-readiness pull reads.
 *
 * has_free_shipping is credited ONLY from a real enabled offline free-shipping
 * carrier OR an automatic (no-coupon), in-window, website + guest-group cart price
 * rule — never fabricated. transit_days are ALWAYS null: Magento has no transit/EDD
 * data anywhere in core (it's a 3rd-party extension feature), so we never invent it.
 *
 * tablerate + scope are flattened to scalar getters (tablerate_*, website_id) — the
 * webapi serializer favours flat over deeply-nested objects; the connector maps them.
 * Every getter carries an @return docblock (the serializer reads the docblock).
 */
interface ShippingConfigInterface
{
    /**
     * Whether the store offers free shipping (enabled offline carrier OR automatic rule).
     *
     * @return bool
     */
    public function getHasFreeShipping(): bool;

    /**
     * Set whether the store offers free shipping.
     *
     * @param bool $v
     * @return $this
     */
    public function setHasFreeShipping(bool $v): self;

    /**
     * Free-shipping subtotal threshold in base currency, or null when not threshold-gated.
     *
     * @return float|null
     */
    public function getFreeShippingThreshold(): ?float;

    /**
     * Set the free-shipping subtotal threshold.
     *
     * @param float|null $v
     * @return $this
     */
    public function setFreeShippingThreshold(?float $v): self;

    /**
     * Currency of the free-shipping threshold (store base currency), or null.
     *
     * @return string|null
     */
    public function getFreeShippingThresholdCurrency(): ?string;

    /**
     * Set the free-shipping threshold currency.
     *
     * @param string|null $v
     * @return $this
     */
    public function setFreeShippingThresholdCurrency(?string $v): self;

    /**
     * Where free shipping came from: "offline_carrier" | "cart_price_rule" | null.
     *
     * @return string|null
     */
    public function getFreeShippingSource(): ?string;

    /**
     * Set the free-shipping source.
     *
     * @param string|null $v
     * @return $this
     */
    public function setFreeShippingSource(?string $v): self;

    /**
     * Active shipping carriers (code + title).
     *
     * @return \Optaeo\Aeo\Api\Data\ShippingCarrierInterface[]
     */
    public function getActiveCarriers(): array;

    /**
     * Set the active shipping carriers.
     *
     * @param \Optaeo\Aeo\Api\Data\ShippingCarrierInterface[] $v
     * @return $this
     */
    public function setActiveCarriers(array $v): self;

    /**
     * Count of active carriers.
     *
     * @return int
     */
    public function getCarrierCount(): int;

    /**
     * Set the active-carrier count.
     *
     * @param int $v
     * @return $this
     */
    public function setCarrierCount(int $v): self;

    /**
     * Flat-rate price (base currency), or null when flatrate is not active.
     *
     * @return float|null
     */
    public function getFlatRatePrice(): ?float;

    /**
     * Set the flat-rate price.
     *
     * @param float|null $v
     * @return $this
     */
    public function setFlatRatePrice(?float $v): self;

    /**
     * Table-rate condition name (e.g. package_weight, package_value), or null.
     *
     * @return string|null
     */
    public function getTablerateCondition(): ?string;

    /**
     * Set the table-rate condition name.
     *
     * @param string|null $v
     * @return $this
     */
    public function setTablerateCondition(?string $v): self;

    /**
     * Lowest table-rate price (base currency), or null.
     *
     * @return float|null
     */
    public function getTableratePriceMin(): ?float;

    /**
     * Set the lowest table-rate price.
     *
     * @param float|null $v
     * @return $this
     */
    public function setTableratePriceMin(?float $v): self;

    /**
     * Highest table-rate price (base currency), or null.
     *
     * @return float|null
     */
    public function getTableratePriceMax(): ?float;

    /**
     * Set the highest table-rate price.
     *
     * @param float|null $v
     * @return $this
     */
    public function setTableratePriceMax(?float $v): self;

    /**
     * Shipping origin country (ISO-2), or null.
     *
     * @return string|null
     */
    public function getOriginCountry(): ?string;

    /**
     * Set the shipping origin country.
     *
     * @param string|null $v
     * @return $this
     */
    public function setOriginCountry(?string $v): self;

    /**
     * Allowed destination countries (ISO-2).
     *
     * @return string[]
     */
    public function getAllowedCountries(): array;

    /**
     * Set the allowed destination countries.
     *
     * @param string[] $v
     * @return $this
     */
    public function setAllowedCountries(array $v): self;

    /**
     * Minimum transit days — ALWAYS null (Magento core has no transit data).
     *
     * @return int|null
     */
    public function getTransitDaysMin(): ?int;

    /**
     * Set the minimum transit days (always null for Magento).
     *
     * @param int|null $v
     * @return $this
     */
    public function setTransitDaysMin(?int $v): self;

    /**
     * Maximum transit days — ALWAYS null (Magento core has no transit data).
     *
     * @return int|null
     */
    public function getTransitDaysMax(): ?int;

    /**
     * Set the maximum transit days (always null for Magento).
     *
     * @param int|null $v
     * @return $this
     */
    public function setTransitDaysMax(?int $v): self;

    /**
     * Store base currency code.
     *
     * @return string
     */
    public function getCurrency(): string;

    /**
     * Set the store base currency code.
     *
     * @param string $v
     * @return $this
     */
    public function setCurrency(string $v): self;

    /**
     * Website id the config was read for.
     *
     * @return int
     */
    public function getWebsiteId(): int;

    /**
     * Set the website id the config was read for.
     *
     * @param int $v
     * @return $this
     */
    public function setWebsiteId(int $v): self;
}
