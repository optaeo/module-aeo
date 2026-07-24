<?php
/**
 * Install data-patch: create the descriptive catalog_product EAV attributes the
 * OptAEO remediation engine writes into (material, color, style, fit, …), assign
 * each to EVERY attribute set, then flush the EAV cache — so attribute-completeness
 * can actually be remediated on a real merchant store.
 *
 * WHY: the OptAEO connector's attributes writeback writes values ONLY into EXISTING
 * user-defined EAV attributes (write-time attribute creation hits the EAV cache trap,
 * so it is deferred to this install patch — the same pattern as CreateGtinAttribute).
 * Without these, a store has nowhere for the connector's attribute keys to land, the
 * writeback skips them, and
 * attribute-completeness scores 0/8 (~8 points lost on every Magento merchant).
 * These 14 are the recurring keys the engine emits across apparel / beauty /
 * accessories (verified against the cross-platform demo output).
 *
 * Unlike gtin (which is in the attribute-eligibility DENY-list so it never inflates
 * scoring), these ARE descriptive — user_defined + text → the connector's
 * isWritableUserAttribute accepts them and they feed attribute-completeness.
 *
 * IDEMPOTENT + NON-CLOBBERING: each attribute is created + assigned ONLY if its
 * attribute_code does not already exist. A real merchant may already have
 * 'color' / 'size' / 'material' (often as swatch selects) — we NEVER overwrite
 * their attribute config; the writeback simply writes values into whatever they
 * have (and skips it if their attribute isn't a writable text field, which is their
 * configuration choice, not ours to change).
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

class CreateDescriptiveAttributes implements DataPatchInterface
{
    /** The recurring descriptive keys the remediation engine emits. */
    public const ATTRIBUTE_CODES = [
        'material', 'color', 'style', 'fit', 'finish', 'size', 'product_type',
        'neckline', 'closure_type', 'occasion', 'pattern', 'sleeve_length',
        'care_instructions', 'application_method',
    ];

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
        $entityTypeId = (int) $eavSetup->getEntityTypeId(Product::ENTITY);
        $attributeSetIds = $eavSetup->getAllAttributeSetIds($entityTypeId);

        $created = 0;
        foreach (self::ATTRIBUTE_CODES as $code) {
            // NON-CLOBBERING: skip entirely if the merchant already has this attribute.
            if ($eavSetup->getAttributeId(Product::ENTITY, $code)) {
                continue;
            }

            $eavSetup->addAttribute(Product::ENTITY, $code, [
                'type' => 'varchar',
                'label' => $this->labelFor($code),
                'input' => 'text',
                'source' => '',
                'frontend' => '',
                'backend' => '',
                // Global so one remediated value serves every store-view.
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

            // Assign to the default group of EVERY attribute set (the cache/attribute-set
            // trap — a value for an attribute not on the product's set is silently dropped).
            foreach ($attributeSetIds as $attributeSetId) {
                $groupId = $eavSetup->getDefaultAttributeGroupId($entityTypeId, $attributeSetId);
                $eavSetup->addAttributeToSet($entityTypeId, $attributeSetId, $groupId, $code);
            }
            $created++;
        }

        $this->moduleDataSetup->getConnection()->endSetup();

        // Flush the EAV (+ config) cache so the next request's product save recognises
        // the new attributes + their set membership immediately (the EAV cache trap).
        // Only needed when we actually created something; harmless either way.
        if ($created > 0) {
            $this->cacheTypeList->cleanType('eav');
            $this->cacheTypeList->cleanType('config');
        }

        return $this;
    }

    private function labelFor(string $code): string
    {
        return ucwords(str_replace('_', ' ', $code));
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
