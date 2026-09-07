<?php
/**
 * Generates the OptAEO-curated, catalogue-aware /llms.txt, /agents.md and
 * /sitemap.xml served at the store root. Content is STORE-TRUTH: it reflects the
 * live catalogue (real store name, real active categories, real visible+enabled
 * products) — no fabrication. OptAEO fetches these at the root for protocol checks,
 * so what is generated here is exactly what is served.
 */
declare(strict_types=1);

namespace Optaeo\Aeo\Model;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product as ProductModel;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class ProtocolContent
{
    private const DIGEST_MAX_CATEGORIES = 50;
    private const DIGEST_MAX_PRODUCTS = 100;
    private const SITEMAP_URL_LIMIT = 50000;
    private const SITEMAP_QUERY_BATCH = 500;

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly ResourceConnection $resource,
        private readonly EavConfig $eavConfig,
        private readonly MetadataPool $metadataPool,
        private readonly TimezoneInterface $timezone
    ) {
    }

    public function llmsTxt(): string
    {
        $ctx = $this->context();
        $lines = [];
        $lines[] = '# ' . $ctx['storeName'];
        $lines[] = '';
        $lines[] = '> Product catalogue for ' . $ctx['storeName']
            . ', served for AI shopping agents by OptAEO (Agentic Engine Optimisation). Base URL: ' . $ctx['baseUrl'];
        $lines[] = '';
        if ($ctx['categories']) {
            $lines[] = '## Categories';
            foreach ($ctx['categories'] as $c) {
                $lines[] = '- [' . $c['name'] . '](' . $c['url'] . ')';
            }
            $lines[] = '';
        }
        if ($ctx['products']) {
            $lines[] = '## Products';
            foreach ($ctx['products'] as $p) {
                $lines[] = '- [' . $p['name'] . '](' . $p['url'] . ')';
            }
            $lines[] = '';
        }
        $lines[] = '## About';
        $lines[] = 'Generated and kept current by OptAEO from the live ' . $ctx['storeName']
            . ' catalogue (' . $ctx['productCount'] . ' visible products; up to '
            . self::DIGEST_MAX_PRODUCTS . ' listed above). '
            . 'Last generated ' . $ctx['date'] . '.';
        $lines[] = '';
        return implode("\n", $lines);
    }

    public function agentsMd(): string
    {
        $ctx = $this->context();
        $lines = [];
        $lines[] = '# ' . $ctx['storeName'] . ' — Agent Guide';
        $lines[] = '';
        $lines[] = 'This file helps AI shopping agents understand and navigate ' . $ctx['storeName'] . '.';
        $lines[] = '';
        $lines[] = '- **Store**: ' . $ctx['storeName'];
        $lines[] = '- **Base URL**: ' . $ctx['baseUrl'];
        $lines[] = '- **Catalogue size**: ' . $ctx['productCount'] . ' visible products';
        $lines[] = '- **Maintained by**: OptAEO — Agentic Engine Optimisation';
        $lines[] = '';
        if ($ctx['categories']) {
            $lines[] = '## Categories';
            foreach ($ctx['categories'] as $c) {
                $lines[] = '- [' . $c['name'] . '](' . $c['url'] . ')';
            }
            $lines[] = '';
        }
        if ($ctx['products']) {
            $lines[] = '## Products';
            foreach ($ctx['products'] as $p) {
                $lines[] = '- [' . $p['name'] . '](' . $p['url'] . ')';
            }
            $lines[] = '';
        }
        $lines[] = '## Notes for agents';
        $lines[] = '- Product titles, descriptions, GTINs and structured data on this store are optimised by OptAEO for accurate agent answers.';
        $lines[] = '- Each product page exposes structured product data for reliable extraction.';
        $lines[] = '';
        $lines[] = '_Last generated ' . $ctx['date'] . '._';
        $lines[] = '';
        return implode("\n", $lines);
    }

    /**
     * Complete the fallible, non-streaming truth reads before response headers.
     *
     * @return array{store:mixed,baseUrl:string,categoryCount:int,productCount:int}
     */
    public function prepareSitemapTruth(): array
    {
        return $this->sitemapTruth();
    }

    /**
     * @param array{store:mixed,baseUrl:string,categoryCount:int,productCount:int}|null $preparedTruth
     * @return iterable<string>
     */
    public function sitemapChunks(?int $page = null, ?array $preparedTruth = null): iterable
    {
        $truth = $preparedTruth ?? $this->sitemapTruth();
        $total = ($truth['baseUrl'] !== '' ? 1 : 0)
            + $truth['categoryCount']
            + $truth['productCount'];

        if ($page === null && $total > self::SITEMAP_URL_LIMIT) {
            $pageCount = (int) ceil($total / self::SITEMAP_URL_LIMIT);
            yield from self::renderSitemapIndexChunks($truth['baseUrl'], $pageCount);
            return;
        }

        if ($page !== null && ($page < 1 || $page > intdiv(PHP_INT_MAX, self::SITEMAP_URL_LIMIT))) {
            yield from self::renderUrlsetChunks([]);
            return;
        }

        $offset = $page === null ? 0 : ($page - 1) * self::SITEMAP_URL_LIMIT;
        $limit = min(self::SITEMAP_URL_LIMIT, max(0, $total - $offset));
        yield from self::renderUrlsetChunks($this->sitemapUrlSlice($truth, $offset, $limit));
    }

    /**
     * Render only supplied store-truth URLs. The seen set is bounded by the
     * sitemap protocol's 50,000-URL document limit.
     *
     * @param iterable<string> $urls
     * @return iterable<string>
     */
    public static function renderUrlsetChunks(iterable $urls): iterable
    {
        yield "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        yield "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        $seen = [];
        foreach ($urls as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $url = trim($candidate);
            if ($url === '' || isset($seen[$url])) {
                continue;
            }
            $escaped = self::escapeSitemapLocation($url);
            if ($escaped === null) {
                continue;
            }
            $seen[$url] = true;
            yield "  <url>\n    <loc>" . $escaped . "</loc>\n  </url>\n";
        }
        yield "</urlset>\n";
    }

    /** @return iterable<string> */
    public static function renderSitemapIndexChunks(string $baseUrl, int $pageCount): iterable
    {
        yield "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        yield "<sitemapindex xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        for ($page = 1; $page <= $pageCount; $page++) {
            $url = $baseUrl . '/sitemap-' . $page . '.xml';
            $escaped = self::escapeSitemapLocation($url);
            if ($escaped === null) {
                continue;
            }
            yield "  <sitemap>\n    <loc>" . $escaped . "</loc>\n  </sitemap>\n";
        }
        yield "</sitemapindex>\n";
    }

    private static function escapeSitemapLocation(string $url): ?string
    {
        $escaped = htmlspecialchars(
            $url,
            ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        if ($escaped === '') {
            return null;
        }
        $illegal = preg_match(
            '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u',
            $escaped
        );
        return $illegal === 0 ? $escaped : null;
    }

    /**
     * @return array{store:mixed,baseUrl:string,categoryCount:int,productCount:int}
     */
    protected function sitemapTruth(): array
    {
        $store = $this->storeManager->getStore();
        $baseUrl = rtrim((string) $store->getBaseUrl(), '/');
        if ($baseUrl === '') {
            throw new \RuntimeException('Magento store base URL is unavailable for sitemap generation');
        }
        if (self::escapeSitemapLocation($baseUrl) === null) {
            throw new \RuntimeException('Magento store base URL contains XML-illegal characters');
        }
        return [
            'store' => $store,
            'baseUrl' => $baseUrl,
            'categoryCount' => $this->countSitemapCategories($store),
            'productCount' => $this->countVisibleProducts($store),
        ];
    }

    /**
     * @param array{store:mixed,baseUrl:string,categoryCount:int,productCount:int} $truth
     * @return iterable<string>
     */
    protected function sitemapUrlSlice(array $truth, int $offset, int $limit): iterable
    {
        if ($limit <= 0) {
            return;
        }
        $remaining = $limit;

        if ($truth['baseUrl'] !== '') {
            if ($offset === 0) {
                yield $truth['baseUrl'];
                $remaining--;
            } else {
                $offset--;
            }
        }
        if ($remaining <= 0) {
            return;
        }

        if ($offset < $truth['categoryCount']) {
            $categoryLimit = min($remaining, $truth['categoryCount'] - $offset);
            yield from $this->sitemapCategoryUrls($truth['store'], $offset, $categoryLimit);
            $remaining -= $categoryLimit;
            $offset = 0;
        } else {
            $offset -= $truth['categoryCount'];
        }
        if ($remaining <= 0) {
            return;
        }

        yield from $this->sitemapProductUrls($truth['store'], $truth['baseUrl'], $offset, $remaining);
    }

    /**
     * Assemble the live store-truth context. Defensive: a catalogue read failure
     * degrades to the store header rather than 500-ing the protocol route.
     *
     * @return array{storeName:string,baseUrl:string,categories:array,products:array,productCount:int,date:string}
     */
    private function context(): array
    {
        $store = $this->storeManager->getStore();
        $baseUrl = rtrim((string) $store->getBaseUrl(), '/');
        $storeName = (string) ($this->scopeConfig->getValue(
            'general/store_information/name',
            ScopeInterface::SCOPE_STORE,
            $store->getId()
        ) ?: $store->getName() ?: $store->getFrontendName());

        $categories = [];
        $products = [];
        $productCount = 0;
        try {
            $catCollection = $this->categoryCollectionFactory->create();
            $catCollection->addAttributeToSelect('name')
                ->addAttributeToFilter('is_active', 1)
                ->addAttributeToFilter('level', ['gteq' => 2])
                ->setStore($store)
                ->setPageSize(self::DIGEST_MAX_CATEGORIES);
            foreach ($catCollection as $cat) {
                $name = trim((string) $cat->getName());
                if ($name === '') {
                    continue;
                }
                $categories[] = ['name' => $name, 'url' => (string) $cat->getUrl()];
            }

            // Enumerate served products from store-truth via a direct query on
            // status + visibility (+ website) with COALESCE(store-override, default).
            // Deliberately NOT the product collection: setVisibility() joins the
            // catalog_category_product index (under-reports under index lag), and the
            // EAV collection's store-scoped filter joins drop products that only carry
            // default-scope value rows (count != loaded). This query depends on NEITHER
            // the category index NOR flat — an enabled + individually-visible product is
            // crawlable via its own URL regardless of category membership, so the
            // AI-crawler surface lists all of them. No over-inclusion (NOT_VISIBLE +
            // disabled stay out).
            [$products, $productCount] = $this->fetchVisibleProducts($store, $baseUrl);
        } catch (\Throwable $e) {
            // Honest degraded state — serve the store header without the catalogue
            // rather than fail the route.
        }

        return [
            'storeName' => $storeName !== '' ? $storeName : 'Store',
            'baseUrl' => $baseUrl,
            'categories' => $categories,
            'products' => $products,
            'productCount' => $productCount,
            'date' => $this->timezone->date()->format('Y-m-d'),
        ];
    }

    private function sitemapCategoryCollection(\Magento\Store\Api\Data\StoreInterface $store)
    {
        $collection = $this->categoryCollectionFactory->create();
        $collection->addAttributeToSelect('name')
            ->addAttributeToFilter('is_active', 1)
            ->addAttributeToFilter('level', ['gteq' => 2])
            ->addAttributeToSort('entity_id', 'ASC')
            ->setStore($store);
        return $collection;
    }

    private function countSitemapCategories(\Magento\Store\Api\Data\StoreInterface $store): int
    {
        return (int) $this->sitemapCategoryCollection($store)->getSize();
    }

    /** @return iterable<string> */
    private function sitemapCategoryUrls(
        \Magento\Store\Api\Data\StoreInterface $store,
        int $offset,
        int $limit
    ): iterable {
        $remaining = $limit;
        $cursor = $offset;
        while ($remaining > 0) {
            $take = min(self::SITEMAP_QUERY_BATCH, $remaining);
            $collection = $this->sitemapCategoryCollection($store);
            $collection->getSelect()->limit($take, $cursor);
            $loaded = 0;
            foreach ($collection as $category) {
                $loaded++;
                $url = trim((string) $category->getUrl());
                if ($url !== '') {
                    yield $url;
                }
            }
            if ($loaded < $take) {
                return;
            }
            $cursor += $loaded;
            $remaining -= $loaded;
        }
    }

    /** Build the enabled-and-visible store query shared by digest and sitemap reads. */
    private function visibleProductBaseSelect(\Magento\Store\Api\Data\StoreInterface $store)
    {
        $conn = $this->resource->getConnection();
        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        $cpe = $this->resource->getTableName('catalog_product_entity');
        $cpei = $this->resource->getTableName('catalog_product_entity_int');
        $cpw = $this->resource->getTableName('catalog_product_website');
        $statusId = (int) $this->eavConfig->getAttribute(ProductModel::ENTITY, 'status')->getAttributeId();
        $visId = (int) $this->eavConfig->getAttribute(ProductModel::ENTITY, 'visibility')->getAttributeId();
        $storeId = (int) $store->getId();
        $websiteId = (int) $store->getWebsiteId();
        $coalesce = static fn (string $alias): string => "COALESCE({$alias}_s.value, {$alias}_d.value)";

        return $conn->select()
            ->from(['e' => $cpe], [])
            ->join(['w' => $cpw], 'w.product_id = e.entity_id AND w.website_id = ' . $websiteId, [])
            ->joinLeft(['st_s' => $cpei], "st_s.{$linkField} = e.{$linkField} AND st_s.attribute_id = {$statusId} AND st_s.store_id = {$storeId}", [])
            ->joinLeft(['st_d' => $cpei], "st_d.{$linkField} = e.{$linkField} AND st_d.attribute_id = {$statusId} AND st_d.store_id = 0", [])
            ->joinLeft(['vs_s' => $cpei], "vs_s.{$linkField} = e.{$linkField} AND vs_s.attribute_id = {$visId} AND vs_s.store_id = {$storeId}", [])
            ->joinLeft(['vs_d' => $cpei], "vs_d.{$linkField} = e.{$linkField} AND vs_d.attribute_id = {$visId} AND vs_d.store_id = 0", [])
            ->where($coalesce('st') . ' = ?', \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED)
            ->where($coalesce('vs') . ' IN (?)', [
                Visibility::VISIBILITY_IN_CATALOG,
                Visibility::VISIBILITY_IN_SEARCH,
                Visibility::VISIBILITY_BOTH,
            ]);
    }

    private function visibleProductDataSelect(
        \Magento\Store\Api\Data\StoreInterface $store,
        bool $includeName
    ) {
        $select = $this->visibleProductBaseSelect($store);
        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        $cpev = $this->resource->getTableName('catalog_product_entity_varchar');
        $storeId = (int) $store->getId();
        $urlKeyId = (int) $this->eavConfig->getAttribute(ProductModel::ENTITY, 'url_key')->getAttributeId();
        $coalesce = static fn (string $alias): string => "COALESCE({$alias}_s.value, {$alias}_d.value)";

        $select
            ->joinLeft(['uk_s' => $cpev], "uk_s.{$linkField} = e.{$linkField} AND uk_s.attribute_id = {$urlKeyId} AND uk_s.store_id = {$storeId}", [])
            ->joinLeft(['uk_d' => $cpev], "uk_d.{$linkField} = e.{$linkField} AND uk_d.attribute_id = {$urlKeyId} AND uk_d.store_id = 0", []);
        $columns = [
            'entity_id' => 'e.entity_id',
            'url_key' => new \Zend_Db_Expr($coalesce('uk')),
        ];
        if ($includeName) {
            $nameId = (int) $this->eavConfig->getAttribute(ProductModel::ENTITY, 'name')->getAttributeId();
            $select
                ->joinLeft(['nm_s' => $cpev], "nm_s.{$linkField} = e.{$linkField} AND nm_s.attribute_id = {$nameId} AND nm_s.store_id = {$storeId}", [])
                ->joinLeft(['nm_d' => $cpev], "nm_d.{$linkField} = e.{$linkField} AND nm_d.attribute_id = {$nameId} AND nm_d.store_id = 0", []);
            $columns['name'] = new \Zend_Db_Expr($coalesce('nm'));
        }
        return $select
            ->columns($columns)
            ->group('e.entity_id')
            ->order('e.entity_id ASC');
    }

    private function countVisibleProducts(\Magento\Store\Api\Data\StoreInterface $store): int
    {
        $select = $this->visibleProductBaseSelect($store);
        $select->columns(new \Zend_Db_Expr('COUNT(DISTINCT e.entity_id)'));
        return (int) $this->resource->getConnection()->fetchOne($select);
    }

    /**
     * STORE-TRUTH digest list. Its readability cap does not apply to sitemap output.
     *
     * @return array{0: array<int, array{name: string, url: string}>, 1: int}
     */
    private function fetchVisibleProducts(\Magento\Store\Api\Data\StoreInterface $store, string $baseUrl): array
    {
        $productCount = $this->countVisibleProducts($store);
        $select = $this->visibleProductDataSelect($store, true)
            ->limit(self::DIGEST_MAX_PRODUCTS);
        $rows = $this->resource->getConnection()->fetchAll($select);
        $storeId = (int) $store->getId();
        $suffix = (string) $this->scopeConfig->getValue(
            'catalog/seo/product_url_suffix',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $products = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $products[] = [
                'name' => $name,
                'url' => $this->productUrl($row, $baseUrl, $suffix),
            ];
        }
        return [$products, $productCount];
    }

    /** @return iterable<string> */
    private function sitemapProductUrls(
        \Magento\Store\Api\Data\StoreInterface $store,
        string $baseUrl,
        int $offset,
        int $limit
    ): iterable {
        if ($limit <= 0) {
            return;
        }
        $lastEntityId = $offset > 0 ? $this->sitemapProductBoundaryId($store, $offset) : 0;
        if ($lastEntityId === null) {
            throw new \RuntimeException('Magento sitemap product boundary read returned no row');
        }
        $suffix = $this->productUrlSuffix($store);
        $remaining = $limit;
        while ($remaining > 0) {
            $take = min(self::SITEMAP_QUERY_BATCH, $remaining);
            $rows = $this->sitemapProductBatch($store, $baseUrl, $suffix, $lastEntityId, $take);
            if (!$rows) {
                return;
            }
            foreach ($rows as $row) {
                $lastEntityId = (int) $row['entity_id'];
                yield $row['url'];
                $remaining--;
                if ($remaining <= 0) {
                    return;
                }
            }
            if (count($rows) < $take) {
                return;
            }
        }
    }

    protected function sitemapProductBoundaryId(
        \Magento\Store\Api\Data\StoreInterface $store,
        int $offset
    ): ?int {
        $boundary = $this->visibleProductBaseSelect($store)
            ->columns(['entity_id' => 'e.entity_id'])
            ->group('e.entity_id')
            ->order('e.entity_id ASC')
            ->limit(1, $offset - 1);
        $boundaryId = $this->resource->getConnection()->fetchOne($boundary);
        return $boundaryId === false || $boundaryId === null ? null : (int) $boundaryId;
    }

    /** @return array<int, array{entity_id:int,url:string}> */
    protected function sitemapProductBatch(
        \Magento\Store\Api\Data\StoreInterface $store,
        string $baseUrl,
        string $suffix,
        int $afterEntityId,
        int $limit
    ): array {
        $select = $this->visibleProductDataSelect($store, false);
        if ($afterEntityId > 0) {
            $select->where('e.entity_id > ?', $afterEntityId);
        }
        $select->limit($limit);
        $rows = $this->resource->getConnection()->fetchAll($select);
        return array_map(fn (array $row): array => [
            'entity_id' => (int) $row['entity_id'],
            'url' => $this->productUrl($row, $baseUrl, $suffix),
        ], $rows);
    }

    protected function productUrlSuffix(\Magento\Store\Api\Data\StoreInterface $store): string
    {
        return (string) $this->scopeConfig->getValue(
            'catalog/seo/product_url_suffix',
            ScopeInterface::SCOPE_STORE,
            (int) $store->getId()
        );
    }

    /** @param array{entity_id:mixed,url_key?:mixed} $row */
    private function productUrl(array $row, string $baseUrl, string $suffix): string
    {
        $urlKey = trim((string) ($row['url_key'] ?? ''));
        return $urlKey !== ''
            ? $baseUrl . '/' . $urlKey . $suffix
            : $baseUrl . '/catalog/product/view/id/' . (int) $row['entity_id'];
    }
}
