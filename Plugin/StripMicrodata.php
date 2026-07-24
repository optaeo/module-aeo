<?php
/**
 * Strips schema.org MICRODATA attributes (itemscope / itemtype / itemprop) from the
 * rendered HTML of the SPECIFIC blocks it is bound to (see etc/frontend/di.xml — the
 * price render(s), review-summary render, gallery, and the page title). This is the
 * "own the schema" suppression for the microdata sources that aren't removable via
 * layout arguments, so AI engines see exactly ONE schema.org Product node — our
 * authoritative JSON-LD.
 *
 * Targeted, not wholesale: bound only to those render blocks, and it removes ONLY
 * microdata attributes — never classes, ids, or structure — so there is no layout/
 * CSS/JS impact. It is idempotent and early-returns when a block emits no microdata,
 * so binding a class that renders none on a given theme is a safe no-op (the same
 * di.xml serves both the Luma and Hyvä storefronts).
 *
 * Both QUOTED and UNQUOTED attribute forms are handled: Luma quotes its microdata
 * (itemprop="offers"), whereas Hyvä emits some unquoted (itemprop=name on the H1 —
 * Hyvä's theme layout re-sets add_base_attribute after our module layout, defeating
 * the layout-arg suppression, so we strip it here instead).
 */
declare(strict_types=1);

namespace Optaeo\Aeo\Plugin;

use Magento\Framework\View\Element\AbstractBlock;

class StripMicrodata
{
    public function afterToHtml(AbstractBlock $subject, $result)
    {
        $html = (string) $result;
        if ($html === '' || stripos($html, 'item') === false) {
            return $result;
        }
        // Order: itemprop first, then itemtype, then itemscope (boolean or valued).
        // Quoted forms (Luma):
        $html = preg_replace('/\s+itemprop=("|\')[^"\']*\1/i', '', $html);
        $html = preg_replace('/\s+itemtype=("|\')https?:\/\/schema\.org\/[^"\']*\1/i', '', $html);
        $html = preg_replace('/\s+itemscope(=("|\')[^"\']*\2)?/i', '', $html);
        // Unquoted forms (Hyvä, e.g. itemprop=name on the H1). Value runs until the
        // next whitespace/quote/'>'. Quoted values are already gone above; their
        // leading '"' is in the excluded class, so these patterns can't touch them.
        $html = preg_replace('/\s+itemprop=[^\s"\'>]+/i', '', $html);
        $html = preg_replace('/\s+itemtype=https?:\/\/schema\.org\/[^\s"\'>]+/i', '', $html);
        return $html;
    }
}
