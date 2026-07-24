<?php
declare(strict_types=1);

namespace Optaeo\Aeo\Model;

use Magento\Framework\DataObject;
use Optaeo\Aeo\Api\Data\ReviewSummaryInterface;

class ReviewSummary extends DataObject implements ReviewSummaryInterface
{
    /**
     * @inheritDoc
     */
    public function getSku(): ?string
    {
        $v = $this->getData(self::SKU);
        return $v === null ? null : (string) $v;
    }

    /**
     * @inheritDoc
     */
    public function setSku(?string $sku): self
    {
        return $this->setData(self::SKU, $sku);
    }

    /**
     * @inheritDoc
     */
    public function getReviewCount(): int
    {
        return (int) $this->getData(self::REVIEW_COUNT);
    }

    /**
     * @inheritDoc
     */
    public function setReviewCount(int $count): self
    {
        return $this->setData(self::REVIEW_COUNT, $count);
    }

    /**
     * @inheritDoc
     */
    public function getAvgRating(): ?float
    {
        $v = $this->getData(self::AVG_RATING);
        return $v === null ? null : (float) $v;
    }

    /**
     * @inheritDoc
     */
    public function setAvgRating(?float $rating): self
    {
        return $this->setData(self::AVG_RATING, $rating);
    }
}
