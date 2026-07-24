<?php
declare(strict_types=1);

namespace Optaeo\Aeo\Api;

use Optaeo\Aeo\Api\Data\ReviewSummaryInterface;

/**
 * Read-only review-summary service exposed over REST for the OptAEO connector's
 * OAuth1 Integration token. Core Magento has no reviews REST — this is the gap-fill.
 */
interface ReviewSummaryManagementInterface
{
    /**
     * Review summary for one product by SKU.
     *
     * @param string $sku
     * @return \Optaeo\Aeo\Api\Data\ReviewSummaryInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getBySku(string $sku): ReviewSummaryInterface;

    /**
     * Review summaries for every product that has at least one review (current store).
     *
     * @return \Optaeo\Aeo\Api\Data\ReviewSummaryInterface[]
     */
    public function getList(): array;
}
