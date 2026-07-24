<?php
/**
 * Single source of per-product review aggregates (core review_entity_summary),
 * shared by the reviews REST endpoint (ReviewSummaryManagement) AND the storefront
 * JSON-LD block — so the served aggregateRating and the API summary can
 * never diverge. avg = rating_summary (0-100 percent) / 20 → 1-5 scale; null when
 * there are no reviews (honest empty state; the rating factor self-removes).
 */
declare(strict_types=1);

namespace Optaeo\Aeo\Model;

use Magento\Framework\App\ResourceConnection;

class ReviewSummarySource
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * @return array{count: int, avg: float|null}
     */
    public function forProduct(int $productId, int $storeId): array
    {
        $conn = $this->resource->getConnection();
        $select = $conn->select()
            ->from(['s' => $this->resource->getTableName('review_entity_summary')], ['reviews_count', 'rating_summary'])
            ->join(
                ['e' => $this->resource->getTableName('review_entity')],
                's.entity_type = e.entity_id AND e.entity_code = ' . $conn->quote('product'),
                []
            )
            ->where('s.entity_pk_value = ?', $productId)
            ->where('s.store_id = ?', $storeId)
            ->limit(1);
        $row = $conn->fetchRow($select) ?: [];

        $count = (int) ($row['reviews_count'] ?? 0);
        $avg = null;
        if ($count > 0 && isset($row['rating_summary']) && $row['rating_summary'] !== null && $row['rating_summary'] !== '') {
            $avg = round(((float) $row['rating_summary']) / 20, 2);
        }
        return ['count' => $count, 'avg' => $avg];
    }
}
