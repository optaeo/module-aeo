<?php
/**
 * Empty-only FAQ restoration. The EAV value is conditional at the database write,
 * not merely preceded by a GET. No product save, indexing, callback, score or
 * merchant field update is performed. Product-tag cache invalidation follows a
 * committed restore so the module's next render can observe the native value.
 */
declare(strict_types=1);

namespace Optaeo\Aeo\Model;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\InputException;
use Optaeo\Aeo\Api\Data\FaqRestorationInterface;
use Optaeo\Aeo\Api\Data\FaqRestorationInterfaceFactory;
use Optaeo\Aeo\Api\FaqRestorationManagementInterface;

class FaqRestorationManagement implements FaqRestorationManagementInterface
{
    public const XML_PATH_ENABLED = 'optaeo/restoration/enabled';
    private const ATTRIBUTE = 'optaeo_faq';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly MetadataPool $metadataPool,
        private readonly ProductRepositoryInterface $products,
        private readonly ScopeConfigInterface $config,
        private readonly ManagerInterface $events,
        private readonly FaqRestorationInterfaceFactory $resultFactory
    ) {
    }

    public function inspect(string $sku): FaqRestorationInterface
    {
        return $this->run($sku, null);
    }

    public function restoreIfEmpty(string $sku, string $payload): FaqRestorationInterface
    {
        $this->validatePayload($payload);
        return $this->run($sku, $payload);
    }

    private function result(string $sku, string $status, string $value = ''): FaqRestorationInterface
    {
        return $this->resultFactory->create(['data' => ['sku' => $sku, 'status' => $status, 'value' => $value]]);
    }

    private function enabled(): bool
    {
        return $this->config->isSetFlag(self::XML_PATH_ENABLED)
            && !$this->config->isSetFlag('advanced/modules_disable_output/Optaeo_Aeo');
    }

    private function validatePayload(string $payload): void
    {
        $items = json_decode($payload, true);
        if (strlen($payload) > 60000 || !is_array($items) || !array_is_list($items)
            || count($items) < 1 || count($items) > 20) {
            throw new InputException(__('Invalid OptAEO FAQ restoration payload.'));
        }
        foreach ($items as $item) {
            if (!is_array($item) || count($item) !== 2 || !isset($item['q'], $item['a'])
                || !is_string($item['q']) || !is_string($item['a'])
                || trim($item['q']) === '' || trim($item['a']) === ''
                || mb_strlen($item['q']) > 300 || mb_strlen($item['a']) > 2000
                || strip_tags($item['q']) !== $item['q'] || strip_tags($item['a']) !== $item['a']) {
                throw new InputException(__('Invalid OptAEO FAQ restoration payload.'));
            }
        }
    }

    private function run(string $sku, ?string $payload): FaqRestorationInterface
    {
        if ($sku === '' || strlen($sku) > 255 || preg_match('/[\x00-\x1f\x7f]/', $sku)) {
            throw new InputException(__('Invalid product SKU.'));
        }
        if (!$this->enabled()) {
            return $this->result($sku, 'disabled');
        }
        // A forced base-scope repository read resolves Commerce's current staged
        // row. Its entity-manager metadata supplies the real EAV link field.
        $product = $this->products->get($sku, false, 0, true);
        $metadata = $this->metadataPool->getMetadata(ProductInterface::class);
        $link = $metadata->getLinkField();
        $entityTable = $metadata->getEntityTable();
        if (!in_array($link, ['entity_id', 'row_id'], true)
            || $entityTable !== $this->resource->getTableName('catalog_product_entity')
            || !method_exists($product, 'getData') || (string) $product->getSku() !== $sku
            || (int) $product->getId() < 1 || (int) $product->getData($link) < 1) {
            return $this->result($sku, 'unsupported');
        }
        if ((int) $product->getStatus() !== 1) {
            return $this->result($sku, 'disabled');
        }
        $db = $this->resource->getConnectionByName($metadata->getEntityConnectionName());
        $quote = fn(string $name): string => $db->quoteIdentifier($name);
        $table = fn(string $name): string => $quote($this->resource->getTableName($name));
        $linkColumn = $quote($link);
        $uniqueSlot = false;
        foreach ($db->getIndexList($this->resource->getTableName('catalog_product_entity_text')) as $index) {
            $columns = $index['COLUMNS_LIST'] ?? [];
            sort($columns);
            $expected = ['attribute_id', 'store_id', $link];
            sort($expected);
            if (($index['INDEX_TYPE'] ?? '') === 'unique' && $columns === $expected) {
                $uniqueSlot = true;
            }
        }
        if (!$uniqueSlot) {
            return $this->result($sku, 'unsupported');
        }
        $db->beginTransaction();
        try {
            $attributes = $db->fetchAll('SELECT a.attribute_id,a.entity_type_id,a.backend_type,a.backend_model,c.is_global'
                . ' FROM ' . $table('eav_attribute') . ' a JOIN ' . $table('eav_entity_type') . ' e ON e.entity_type_id=a.entity_type_id'
                . ' JOIN ' . $table('catalog_eav_attribute') . ' c ON c.attribute_id=a.attribute_id'
                . ' WHERE a.attribute_code=:code AND e.entity_type_code=:type LOCK IN SHARE MODE',
                ['code' => self::ATTRIBUTE, 'type' => 'catalog_product']);
            if (count($attributes) !== 1 || $attributes[0]['backend_type'] !== 'text'
                || (int) $attributes[0]['is_global'] !== 1 || !in_array($attributes[0]['backend_model'], [null, ''], true)) {
                $db->rollBack();
                return $this->result($sku, 'unsupported');
            }
            $attribute = (int) $attributes[0]['attribute_id'];
            $entity = $db->fetchRow('SELECT entity_id,sku,attribute_set_id,' . $linkColumn . ' AS link_value FROM '
                . $quote($entityTable) . ' WHERE ' . $linkColumn . '=:link FOR UPDATE', ['link' => $product->getData($link)]);
            if (!$entity || (string) $entity['sku'] !== $sku || (int) $entity['entity_id'] !== (int) $product->getId()
                || (int) $entity['attribute_set_id'] !== (int) $product->getAttributeSetId()) {
                $db->rollBack();
                return $this->result($sku, 'unsupported');
            }
            $assigned = $db->fetchAll('SELECT attribute_id FROM ' . $table('eav_entity_attribute')
                . ' WHERE attribute_id=:attribute AND attribute_set_id=:set AND entity_type_id=:type LOCK IN SHARE MODE',
                ['attribute' => $attribute, 'set' => $entity['attribute_set_id'], 'type' => $attributes[0]['entity_type_id']]);
            if (count($assigned) !== 1) {
                $db->rollBack();
                return $this->result($sku, 'unsupported');
            }
            // Supported native saves and mass attribute updates persist an
            // explicit clear in the SAME catalog transaction. Holding the
            // product lock above orders this read after any winning clear.
            $intent = $db->fetchRow('SELECT disabled FROM ' . $table('optaeo_faq_restoration_intent')
                . ' WHERE entity_id=:id FOR UPDATE', ['id' => $entity['entity_id']]);
            if ($intent && (int) $intent['disabled'] !== 0) {
                $db->commit();
                return $this->result($sku, 'disabled');
            }
            $valuesTable = $table('catalog_product_entity_text');
            $values = $db->fetchAll('SELECT store_id,value FROM ' . $valuesTable . ' WHERE attribute_id=:attribute AND '
                . $linkColumn . '=:link ORDER BY store_id FOR UPDATE', ['attribute' => $attribute, 'link' => $entity['link_value']]);
            $base = '';
            $override = false;
            foreach ($values as $value) {
                if ($value['value'] !== null && $value['value'] !== '') {
                    if ((int) $value['store_id'] === 0) {
                        $base = (string) $value['value'];
                    } else {
                        $override = true;
                    }
                }
            }
            if ($base !== '' || $override || $payload === null || !$this->enabled()) {
                $db->commit();
                return $this->result($sku, !$this->enabled() ? 'disabled' : ($base !== '' ? 'present' : ($override ? 'conflict' : 'empty')), $base);
            }
            // The unique (attribute,store,link) key is also a final CAS against a
            // merchant write that won before this insert/update acquired its lock.
            $written = $db->query('INSERT INTO ' . $valuesTable . ' (attribute_id,store_id,' . $linkColumn . ',value)'
                . ' VALUES (:attribute,0,:link,:value) ON DUPLICATE KEY UPDATE value=CASE'
                . ' WHEN value IS NULL OR OCTET_LENGTH(value)=0 THEN :replacement ELSE value END',
                ['attribute' => $attribute, 'link' => $entity['link_value'], 'value' => $payload, 'replacement' => $payload])->rowCount();
            $actual = $db->fetchOne('SELECT value FROM ' . $valuesTable . ' WHERE attribute_id=:attribute AND store_id=0 AND '
                . $linkColumn . '=:link', ['attribute' => $attribute, 'link' => $entity['link_value']]);
            $db->commit();
        } catch (\Throwable $error) {
            $db->rollBack();
            throw $error;
        }
        if ($actual !== $payload) {
            return $this->result($sku, 'present', is_string($actual) ? $actual : '');
        }
        if ($written > 0) {
            $this->events->dispatch('clean_cache_by_tags', ['object' => $product]);
        }
        return $this->result($sku, $written > 0 ? 'restored' : 'present', $actual);
    }
}
