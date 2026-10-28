<?php
/** Native FAQ removal intent, committed with the supported Catalog write. */
declare(strict_types=1);

namespace Optaeo\Aeo\Model;

use Magento\Framework\App\ResourceConnection;

class FaqWriteIntent
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /** Lock logical products before native EAV writes, in the restore lock order. */
    public function lockProducts($db, array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn(int $id): bool => $id > 0)));
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) {
            $db->fetchAll('SELECT entity_id FROM '
                . $db->quoteIdentifier($this->resource->getTableName('catalog_product_entity'))
                . ' WHERE entity_id=:id ORDER BY entity_id FOR UPDATE', ['id' => $id]);
        }
    }

    /** Compare the current global FAQ slot under the same Catalog locks. */
    public function faqChanged($db, $subject, $product): bool
    {
        $attribute = $subject->getAttribute('optaeo_faq');
        $link = $subject->getLinkField();
        if (!$attribute || (int) $attribute->getId() < 1 || $attribute->getBackendType() !== 'text'
            || (int) $attribute->getIsGlobal() !== 1 || !in_array($link, ['entity_id', 'row_id'], true)) {
            throw new \LogicException('Native FAQ intent metadata is unsupported.');
        }
        $snapshot = false;
        if ((int) $product->getId() > 0) {
            if ((int) $product->getData($link) < 1) {
                throw new \LogicException('Native FAQ intent link is missing.');
            }
            $snapshot = $db->fetchRow('SELECT value FROM '
                . $db->quoteIdentifier($this->resource->getTableName('catalog_product_entity_text'))
                . ' WHERE attribute_id=:attribute AND store_id=0 AND ' . $db->quoteIdentifier($link) . '=:link FOR UPDATE',
                ['attribute' => $attribute->getId(), 'link' => $product->getData($link)]);
        }
        $previous = $snapshot ? $snapshot['value'] : null;
        $next = $product->getData('optaeo_faq');
        // Absence, SQL NULL and zero-byte text are the same empty slot for
        // restoration. Saving an unchanged empty slot is not a deliberate clear.
        return !(in_array($previous, [null, ''], true) && in_array($next, [null, ''], true))
            && $previous !== $next;
    }

    public function record($db, array $ids, $value): void
    {
        if ($db->getTransactionLevel() < 1) {
            throw new \LogicException('FAQ removal intent requires the Catalog transaction.');
        }
        // Non-string native values cannot constitute an explicit nonempty FAQ.
        // Only a new nonempty write re-enables future loss repair after a clear.
        $disabled = is_string($value) && $value !== '' ? 0 : 1;
        $table = $db->quoteIdentifier($this->resource->getTableName('optaeo_faq_restoration_intent'));
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if ($id > 0) {
                $db->query('INSERT INTO ' . $table . ' (entity_id,disabled) VALUES (:id,:disabled)'
                    . ' ON DUPLICATE KEY UPDATE disabled=:replacement',
                    ['id' => $id, 'disabled' => $disabled, 'replacement' => $disabled]);
            }
        }
    }
}
