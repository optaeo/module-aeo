<?php
/**
 * Install data-patch: create the catalog_product EAV attribute `optaeo_faq` and assign
 * it to EVERY attribute set, then flush the EAV cache so the value persists on the very
 * first write (the EAV attribute-set cache trap: a value for an attribute the running
 * app's cached EAV metadata doesn't yet know — or that isn't on the product's attribute
 * set — is silently DROPPED with a 200, never persisted). Same shape as CreateGtinAttribute.
 *
 * WHAT IT HOLDS: a compact JSON array of the product's FAQ, [{"q":"…","a":"…"}, …],
 * written by the OptAEO connector's faq writeback and read back server-side by
 * Block/ProductJsonLd to render a real schema.org FAQPage node. This is what makes the
 * FAQ STORE-TRUTH: it lives on the product, survives a full sync, and needs no callback
 * to OptAEO.
 *
 * user_defined = FALSE on purpose. The connector's attribute-eligibility rule surfaces
 * is_user_defined=1 attributes into products.attributes for attribute-completeness
 * scoring; `optaeo_faq` is an OptAEO delivery slot, not a descriptive merchant
 * attribute, and surfacing it would fake a completeness pass (the scorer credits ANY
 * 4+ filled keys). The connector ALSO deny-lists the code on both the read and write
 * halves — defense in depth.
 *
 * `visible = false` keeps the JSON blob out of the admin product form; it is machine
 * data, not something a merchant hand-edits.
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

class CreateFaqAttribute implements DataPatchInterface
{
    public const ATTRIBUTE_CODE = 'optaeo_faq';

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
                // `text` => MySQL TEXT (64KB) — comfortably above the payload cap
                // (20 Q&A x ~2.3KB) enforced by the connector's canonicaliser.
                'type' => 'text',
                'label' => 'OptAEO FAQ (JSON)',
                'input' => 'textarea',
                'source' => '',
                'frontend' => '',
                'backend' => '',
                // GLOBAL: one FAQ per product, inherited by every store view — matches
                // the base-scope (/rest/all/V1) write the connector performs.
                'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
                'visible' => false,
                'required' => false,
                'user_defined' => false,
                'default' => '',
                'searchable' => false,
                'filterable' => false,
                'comparable' => false,
                'visible_on_front' => false,
                'used_in_product_listing' => false,
                'unique' => false,
                'apply_to' => '',
                'is_used_in_grid' => false,
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
        // the new attribute + its set membership immediately — without this an optaeo_faq
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
