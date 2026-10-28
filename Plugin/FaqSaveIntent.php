<?php
declare(strict_types=1);

namespace Optaeo\Aeo\Plugin;

use Magento\Catalog\Model\ResourceModel\Product;
use Magento\Framework\DataObject;
use Magento\Framework\Model\AbstractModel;
use Optaeo\Aeo\Model\FaqWriteIntent;

/** Repository/Admin product saves reach this Catalog resource, before its commit. */
class FaqSaveIntent
{
    public function __construct(private readonly FaqWriteIntent $intent)
    {
    }

    public function aroundSave(Product $subject, callable $proceed, AbstractModel $product)
    {
        if (!$product->hasData('optaeo_faq')) {
            return $proceed($product);
        }
        return $this->withIntent($subject, fn() => $proceed($product), $product, true);
    }

    public function aroundSaveAttribute(Product $subject, callable $proceed, DataObject $product, $attributeCode)
    {
        if ($attributeCode !== 'optaeo_faq') {
            return $proceed($product, $attributeCode);
        }
        return $this->withIntent($subject, fn() => $proceed($product, $attributeCode), $product);
    }

    private function withIntent(Product $subject, callable $proceed, DataObject $product, bool $compareSnapshot = false)
    {
        $value = $product->getData('optaeo_faq');
        $db = $subject->getConnection();
        $db->beginTransaction();
        try {
            $this->intent->lockProducts($db, [$product->getId()]);
            // Full Product saves carry loaded, unchanged attributes too. Like
            // core UpdateHandler, compare the real EAV snapshot; getOrigData
            // alone is insufficient on repository DTOs without that snapshot.
            $changed = !$compareSnapshot || $this->intent->faqChanged($db, $subject, $product);
            $result = $proceed();
            // The outer level holds core's nested commit until the intent is
            // durable too. A failed native save or marker rolls both back.
            if ($changed) {
                $this->intent->record($db, [$product->getId()], $value);
            }
            $db->commit();
            return $result;
        } catch (\Throwable $error) {
            $db->rollBack();
            throw $error;
        }
    }
}
