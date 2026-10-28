<?php
declare(strict_types=1);

namespace Optaeo\Aeo\Plugin;

use Magento\Catalog\Model\ResourceModel\Product\Action;
use Optaeo\Aeo\Model\FaqWriteIntent;

/** Mass-attribute updates have their own resource transaction, not product save. */
class FaqAttributeIntent
{
    public function __construct(private readonly FaqWriteIntent $intent)
    {
    }

    public function aroundUpdateAttributes(Action $subject, callable $proceed, $entityIds, $attrData, $storeId)
    {
        if (!is_array($attrData) || !array_key_exists('optaeo_faq', $attrData)) {
            return $proceed($entityIds, $attrData, $storeId);
        }
        $db = $subject->getConnection();
        $db->beginTransaction();
        try {
            $this->intent->lockProducts($db, $entityIds);
            $result = $proceed($entityIds, $attrData, $storeId);
            $this->intent->record($db, $entityIds, $attrData['optaeo_faq']);
            $db->commit();
            return $result;
        } catch (\Throwable $error) {
            $db->rollBack();
            throw $error;
        }
    }
}
