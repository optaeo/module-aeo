<?php
declare(strict_types=1);

namespace Optaeo\Aeo\Api;

interface FaqRestorationManagementInterface
{
    /**
     * Inspect the global FAQ slot and every existing store override, without writes.
     * @param string $sku Exact catalog product SKU.
     * @return \Optaeo\Aeo\Api\Data\FaqRestorationInterface
     */
    public function inspect(string $sku): \Optaeo\Aeo\Api\Data\FaqRestorationInterface;

    /**
     * Restore an absent FAQ only. A nonempty value is never replaced or cleared.
     * @param string $sku Exact catalog product SKU.
     * @param string $payload Previously delivered canonical JSON array of q/a pairs.
     * @return \Optaeo\Aeo\Api\Data\FaqRestorationInterface
     */
    public function restoreIfEmpty(string $sku, string $payload): \Optaeo\Aeo\Api\Data\FaqRestorationInterface;
}
