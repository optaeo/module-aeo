<?php
/**
 * Generates the OptAEO-curated, catalogue-aware /llms.txt and /agents.md served at
 * the store root. Content is STORE-TRUTH: it reflects the live catalogue (real store
 * name, real active categories, real visible+enabled products) — no fabrication. The
 * OptAEO scorer fetches these at the root to score protocol readiness, so what is
 * generated here is exactly what is served.
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
    private const MAX_CATEGORIES = 50;
    private const MAX_PRODUCTS = 100;

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
            . ' catalogue (' . $ctx['productCount'] . ' visible products; up to ' . self::MAX_PRODUCTS . ' listed above). '
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
                ->setPageSize(self::MAX_CATEGORIES);
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

    /**
     * STORE-TRUTH visible-product list via direct SQL — depends on NEITHER the
     * category-product index NOR the flat table. Effective status/visibility/name/
     * url_key = COALESCE(store-view override, default). Returns [rows, totalCount].
     *
     * @return array{0: array<int, array{name: string, url: string}>, 1: int}
     */
    private function fetchVisibleProducts(\Magento\Store\Api\Data\StoreInterface $store, string $baseUrl): array
    {
        $conn = $this->resource->getConnection();
        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        $cpe = $this->resource->getTableName('catalog_product_entity');
        $cpei = $this->resource->getTableName('catalog_product_entity_int');
        $cpev = $this->resource->getTableName('catalog_product_entity_varchar');
        $cpw = $this->resource->getTableName('catalog_product_website');

        $statusId = (int) $this->eavConfig->getAttribute(ProductModel::ENTITY, 'status')->getAttributeId();
        $visId = (int) $this->eavConfig->getAttribute(ProductModel::ENTITY, 'visibility')->getAttributeId();
        $nameId = (int) $this->eavConfig->getAttribute(ProductModel::ENTITY, 'name')->getAttributeId();
        $urlKeyId = (int) $this->eavConfig->getAttribute(ProductModel::ENTITY, 'url_key')->getAttributeId();
        $storeId = (int) $store->getId();
        $websiteId = (int) $store->getWebsiteId();

        $coalesce = static fn (string $alias): string => "COALESCE({$alias}_s.value, {$alias}_d.value)";

        // Base: website membership + effective status/visibility (store override → default).
        $base = $conn->select()
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

        // Total enabled+visible count (store-truth; may exceed MAX_PRODUCTS).
        $countSelect = clone $base;
        $countSelect->columns(new \Zend_Db_Expr('COUNT(DISTINCT e.entity_id)'));
        $productCount = (int) $conn->fetchOne($countSelect);

        // Listed rows (name + url_key), capped.
        $dataSelect = clone $base;
        $dataSelect
            ->joinLeft(['nm_s' => $cpev], "nm_s.{$linkField} = e.{$linkField} AND nm_s.attribute_id = {$nameId} AND nm_s.store_id = {$storeId}", [])
            ->joinLeft(['nm_d' => $cpev], "nm_d.{$linkField} = e.{$linkField} AND nm_d.attribute_id = {$nameId} AND nm_d.store_id = 0", [])
            ->joinLeft(['uk_s' => $cpev], "uk_s.{$linkField} = e.{$linkField} AND uk_s.attribute_id = {$urlKeyId} AND uk_s.store_id = {$storeId}", [])
            ->joinLeft(['uk_d' => $cpev], "uk_d.{$linkField} = e.{$linkField} AND uk_d.attribute_id = {$urlKeyId} AND uk_d.store_id = 0", [])
            ->columns([
                'entity_id' => 'e.entity_id',
                'name' => new \Zend_Db_Expr($coalesce('nm')),
                'url_key' => new \Zend_Db_Expr($coalesce('uk')),
            ])
            ->group('e.entity_id')
            ->order('e.entity_id ASC')
            ->limit(self::MAX_PRODUCTS);
        $rows = $conn->fetchAll($dataSelect);

        $suffix = (string) $this->scopeConfig->getValue('catalog/seo/product_url_suffix', ScopeInterface::SCOPE_STORE, $storeId);
        $products = [];
        foreach ($rows as $r) {
            $name = trim((string) ($r['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $urlKey = trim((string) ($r['url_key'] ?? ''));
            $url = $urlKey !== ''
                ? $baseUrl . '/' . $urlKey . $suffix
                : $baseUrl . '/catalog/product/view/id/' . (int) $r['entity_id'];
            $products[] = ['name' => $name, 'url' => $url];
        }
        return [$products, $productCount];
    }
}
