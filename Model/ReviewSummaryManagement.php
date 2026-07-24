<?php
/**
 * Reads Magento core review aggregates (review_entity_summary) per product for the
 * current store. avg_rating = rating_summary (0-100 percent) / 20 → 1-5 scale; null
 * when there are no reviews (honest empty state — the connector's reviews-ratings
 * factor self-removes rather than scoring a zero).
 */
declare(strict_types=1);

namespace Optaeo\Aeo\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Optaeo\Aeo\Api\Data\ReviewSummaryInterface;
use Optaeo\Aeo\Api\Data\ReviewSummaryInterfaceFactory;
use Optaeo\Aeo\Api\ReviewSummaryManagementInterface;

class ReviewSummaryManagement implements ReviewSummaryManagementInterface
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly ReviewSummaryInterfaceFactory $summaryFactory,
        private readonly ReviewSummarySource $summarySource
    ) {
    }

    public function getBySku(string $sku): ReviewSummaryInterface
    {
        // Throws NoSuchEntityException (→ 404) if the SKU doesn't exist.
        $product = $this->productRepository->get($sku);
        $storeId = (int) $this->storeManager->getStore()->getId();
        // SAME source as the storefront JSON-LD aggregateRating (ReviewSummarySource).
        $summary = $this->summarySource->forProduct((int) $product->getId(), $storeId);
        return $this->build($sku, $summary['count'], $summary['avg']);
    }

    public function getList(): array
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        $conn = $this->resource->getConnection();
        // DISCOVERY ONLY: which products carry reviews in this store. The summary
        // numbers (count + the rating_summary/20 avg) come from ReviewSummarySource,
        // the single canonical source shared with getBySku + the storefront JSON-LD
        // aggregateRating — so no surface can compute the average differently.
        $select = $conn->select()
            ->from(['s' => $this->resource->getTableName('review_entity_summary')], [])
            ->join(
                ['e' => $this->resource->getTableName('review_entity')],
                's.entity_type = e.entity_id AND e.entity_code = ' . $conn->quote('product'),
                []
            )
            ->join(
                ['cpe' => $this->resource->getTableName('catalog_product_entity')],
                'cpe.entity_id = s.entity_pk_value',
                ['product_id' => 'entity_id', 'sku']
            )
            ->where('s.store_id = ?', $storeId)
            ->where('s.reviews_count > 0');
        $rows = $conn->fetchAll($select);

        $out = [];
        foreach ($rows as $r) {
            $summary = $this->summarySource->forProduct((int) $r['product_id'], $storeId);
            if ($summary['count'] > 0) {
                $out[] = $this->build((string) $r['sku'], $summary['count'], $summary['avg']);
            }
        }
        return $out;
    }

    private function build(string $sku, int $count, ?float $avg): ReviewSummaryInterface
    {
        return $this->summaryFactory->create()
            ->setSku($sku)
            ->setReviewCount($count)
            ->setAvgRating($avg);
    }
}
