<?php
/**
 * Install data-patch: create the catalog_product EAV attribute `gtin` and assign it
 * to EVERY attribute set, then flush the EAV cache so the value persists on the
 * very first write (the EAV attribute-set cache trap: a value for an attribute the
 * running app's cached EAV metadata doesn't yet know — or that isn't on the product's
 * attribute set — is silently DROPPED with a 200, never persisted).
 *
 * The OptAEO connector's gtin writeback reads + writes EXACTLY this attribute_code.
 * On a real merchant store this patch is what makes that attribute exist.
 *
 * gtin is handled by its own writeback branch (confidence-tiered merchant-Apply
 * gate), NOT the attributes-blob eligibility rule, so user_defined=true is correct
 * (it must NOT inflate attribute-completeness scoring — the scorer excludes it via
 * its dedicated mapping, and the connector's attribute-eligibility deny-list lists
 * `gtin`).
 */
declare(strict_types=1);

namespace Optaeo\Aeo\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class CreateGtinAttribute implements DataPatchInterface
{
    public const ATTRIBUTE_CODE = 'gtin';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory,
        private readonly TypeListInterface $cacheTypeList
    ) {
    }

    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        if (!$eavSetup->getAttributeId(Product::ENTITY, self::ATTRIBUTE_CODE)) {
            $eavSetup->addAttribute(Product::ENTITY, self::ATTRIBUTE_CODE, [
                'type' => 'varchar',
                'label' => 'GTIN',
                'input' => 'text',
                'source' => '',
                'frontend' => '',
                'backend' => '',
                'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
                'visible' => true,
                'required' => false,
                'user_defined' => true,
                'default' => '',
                'searchable' => false,
                'filterable' => false,
                'comparable' => false,
                'visible_on_front' => false,
                'used_in_product_listing' => true,
                'unique' => false,
                'apply_to' => '',
                'is_used_in_grid' => true,
                'is_visible_in_grid' => false,
                'is_filterable_in_grid' => false,
                'group' => 'General',
            ]);
        }

        // Assign to the default group of EVERY attribute set so the value persists on
        // any product regardless of its set (the cache/attribute-set trap). addAttribute
        // above only guarantees the default set; this covers custom sets too.
        $entityTypeId = (int) $eavSetup->getEntityTypeId(Product::ENTITY);
        $attributeSetIds = $eavSetup->getAllAttributeSetIds($entityTypeId);
        foreach ($attributeSetIds as $attributeSetId) {
            $groupId = $eavSetup->getDefaultAttributeGroupId($entityTypeId, $attributeSetId);
            $eavSetup->addAttributeToSet($entityTypeId, $attributeSetId, $groupId, self::ATTRIBUTE_CODE);
        }

        $this->moduleDataSetup->getConnection()->endSetup();

        // Flush the EAV (+ config) cache so the NEXT request's product save recognises
        // the new attribute + its set membership immediately — without this a gtin
        // write right after install returns 200 but silently drops (the EAV cache trap).
        $this->cacheTypeList->cleanType('eav');
        $this->cacheTypeList->cleanType('config');

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
