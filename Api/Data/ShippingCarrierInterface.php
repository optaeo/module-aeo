<?php
declare(strict_types=1);

namespace Optaeo\Aeo\Api\Data;

/**
 * One active shipping carrier (code + human title). Part of the store-level shipping
 * config the OptAEO connector reads. Every getter carries an @return docblock —
 * Magento's webapi serializer reads the docblock, not the PHP return type.
 */
interface ShippingCarrierInterface
{
    public const CODE = 'code';
    public const TITLE = 'title';

    /**
     * Carrier code (e.g. flatrate, freeshipping, tablerate).
     *
     * @return string|null
     */
    public function getCode(): ?string;

    /**
     * Set the carrier code.
     *
     * @param string|null $code
     * @return $this
     */
    public function setCode(?string $code): self;

    /**
     * Carrier title as configured in the admin.
     *
     * @return string|null
     */
    public function getTitle(): ?string;

    /**
     * Set the carrier title.
     *
     * @param string|null $title
     * @return $this
     */
    public function setTitle(?string $title): self;
}
