<?php
declare(strict_types=1);

namespace Optaeo\Aeo\Api\Data;

/**
 * Per-product review summary returned by the OptAEO reviews API — fills Magento
 * core's missing reviews REST so the connector can populate the reviews-ratings
 * factor. avg_rating is on the 1-5 scale (null when there are no reviews).
 *
 * Every method carries an @return docblock — Magento's webapi output serializer
 * (Reflection\TypeProcessor) reads the docblock, not the PHP return type.
 */
interface ReviewSummaryInterface
{
    public const SKU = 'sku';
    public const REVIEW_COUNT = 'review_count';
    public const AVG_RATING = 'avg_rating';

    /**
     * Product SKU.
     *
     * @return string|null
     */
    public function getSku(): ?string;

    /**
     * Set the product SKU.
     *
     * @param string|null $sku
     * @return $this
     */
    public function setSku(?string $sku): self;

    /**
     * Number of approved reviews for this product on the current store.
     *
     * @return int
     */
    public function getReviewCount(): int;

    /**
     * Set the approved-review count.
     *
     * @param int $count
     * @return $this
     */
    public function setReviewCount(int $count): self;

    /**
     * Average rating on the 1-5 scale, or null when there are no reviews.
     *
     * @return float|null
     */
    public function getAvgRating(): ?float;

    /**
     * Set the average rating (1-5 scale, or null when there are no reviews).
     *
     * @param float|null $rating
     * @return $this
     */
    public function setAvgRating(?float $rating): self;
}
