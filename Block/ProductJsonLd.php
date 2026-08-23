<?php
/**
 * Emits ONE authoritative schema.org Product JSON-LD node, generated SERVER-SIDE
 * from the LIVE product at render time — so it always reflects the current store
 * state. Every field is included ONLY when present on the product (honest omission,
 * never an empty/fake value), mirroring the canonical OptAEO schema shape and the
 * store-truth discipline used across the connector.
 *
 * Luma storefront scope. FAQ IS emitted — but ONLY from store-truth: the connector
 * delivers the product's Q&A into the `optaeo_faq` EAV attribute (see
 * Setup/Patch/Data/CreateFaqAttribute), and this block renders it server-side into a
 * real schema.org FAQPage node on the same JSON-LD object as the Product. A product
 * with no stored FAQ emits NO FAQ node and nothing is removed — never an empty or
 * fabricated FAQ.
 */
declare(strict_types=1);

namespace Optaeo\Aeo\Block;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Optaeo\Aeo\Api\ShippingConfigManagementInterface;
use Optaeo\Aeo\Model\ReviewSummarySource;

class ProductJsonLd extends Template
{
    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly ReviewSummarySource $reviewSummarySource,
        private readonly ShippingConfigManagementInterface $shippingConfigManagement,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /** JSON-LD string for the current product, or '' when there is no product. */
    public function getJsonLd(): string
    {
        $product = $this->getCurrentProduct();
        if ($product === null) {
            return '';
        }
        $store = $this->_storeManager->getStore();

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => (string) $product->getName(),
        ];

        $description = trim(strip_tags((string) $product->getData('description')));
        if ($description !== '') {
            $data['description'] = $description;
        }
        if ((string) $product->getSku() !== '') {
            $data['sku'] = (string) $product->getSku();
        }
        // GTIN from the data-patch EAV attribute. schema.org "gtin" (generic) is the
        // correct, universally-accepted property; emit only a well-formed value.
        $gtin = preg_replace('/[\s-]/', '', (string) $product->getData('gtin'));
        if ($gtin !== '' && preg_match('/^\d{8,14}$/', $gtin)) {
            $data['gtin'] = $gtin;
        }
        $brand = $this->resolveBrand($product);
        if ($brand !== null) {
            $data['brand'] = ['@type' => 'Brand', 'name' => $brand];
        }
        $images = $this->resolveImages($product);
        if ($images !== []) {
            $data['image'] = $images;
        }
        $category = $this->resolveCategory($product);
        if ($category !== null) {
            $data['category'] = $category;
        }
        $url = (string) $product->getProductUrl();
        if ($url !== '') {
            $data['url'] = $url;
        }

        // offers — always present (mirrors the canonical generateSchemaOrg shape;
        // the scorer checks Offer field presence). price/availability are store-truth.
        $offer = [
            '@type' => 'Offer',
            'price' => $this->resolvePrice($product),
            'priceCurrency' => (string) $store->getCurrentCurrencyCode(),
            'availability' => $product->isAvailable()
                ? 'https://schema.org/InStock'
                : 'https://schema.org/OutOfStock',
            // Magento has no native condition attribute; NewCondition is the retail
            // default + Google rich-results convention (documented, not a per-product
            // value). Drop this line if a condition attribute is ever modelled.
            'itemCondition' => 'https://schema.org/NewCondition',
        ];
        if ($url !== '') {
            $offer['url'] = $url;
        }
        // OfferShippingDetails — store-truth shipping legibility for AI shopping agents,
        // and served-node PARITY with the Shopify/Woo connectors (which enrich the Offer
        // with the SAME shape from OptAEO's product_shipping_profile). The Magento module
        // rebuilds the node server-side and has no channel to that table, so it composes
        // the equivalent LIVE from the store's own shipping config — the very source
        // OptAEO pulls to populate product_shipping_profile (main repo: lib/magento/
        // shipping.ts). Honest omission throughout: emitted only when the store genuinely
        // has shipping to represent; transit is ALWAYS absent (Magento core carries no
        // EDD data — never fabricated); a free rate appears ONLY when free shipping is
        // actually configured. This does NOT change the shipping-readiness SCORE (that
        // reads product_shipping_profile, not the served node) — it closes the served
        // store-truth gap so a crawler can answer "do you ship to X / is it free?".
        $shippingDetails = $this->resolveShippingDetails();
        if ($shippingDetails !== []) {
            $offer['shippingDetails'] = $shippingDetails;
        }
        $data['offers'] = $offer;

        // aggregateRating — ONLY when reviews exist (same source as the reviews
        // endpoint, ReviewSummarySource). Omit entirely otherwise (no fake 0).
        $summary = $this->reviewSummarySource->forProduct((int) $product->getId(), (int) $store->getId());
        if ($summary['count'] > 0 && $summary['avg'] !== null) {
            $data['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => $summary['avg'],
                'reviewCount' => $summary['count'],
            ];
        }

        // FAQPage — store-truth only, read from the `optaeo_faq` EAV attribute the
        // connector writes (and confirms via a polled read-back). Emitted on the SAME
        // node as the Product, mirroring OptAEO's canonical products.schema_org shape
        // (@type: ["Product","FAQPage"] + mainEntity). Empty/invalid => NO FAQ node.
        $faq = $this->resolveFaq($product);
        if ($faq !== []) {
            $data['@type'] = ['Product', 'FAQPage'];
            $data['mainEntity'] = $faq;
        }

        // Spec attributes as PropertyValue entries. WHY: OptAEO writes descriptive
        // attributes into Magento's EAV, but a written attribute renders nowhere on the
        // storefront until the merchant sets is_visible_on_front=1 — so a correct write
        // can still be invisible to an AI crawler. Emitting them here makes them legible
        // with NO merchant configuration change.
        //
        // Composed from the LIVE product (never a cached copy shipped from OptAEO): the
        // module has no channel carrying OptAEO's products.schema_org, and reading EAV
        // directly is both store-truthful and self-healing when a merchant edits an
        // attribute in admin. It also lets getAttributeText() resolve select/multiselect
        // OPTION IDS into labels — a crawler needs "Navy", not "13".
        //
        // The marker is appended LAST and always emitted, so the deliver-first probe's
        // Array.some match on propertyID is unaffected by these entries.
        $data['additionalProperty'] = array_merge($this->resolveSpecProperties($product), [[
            // OptAEO provenance marker — lets the deliver-first probe (main repo:
            // app/lib/protocols/schema-served-check.ts) POSITIVELY confirm THIS node is
            // OURS, not a foreign SEO extension's Product JSON-LD. On Magento our node
            // shape is structurally identical to competitors', so @type+name are NOT
            // enough to discriminate; this schema.org-valid marker is. Honest, not fake.
            '@type' => 'PropertyValue',
            'propertyID' => 'optaeo:generator',
            'value' => 'OptAEO',
        ]]);

        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function getCurrentProduct(): ?ProductInterface
    {
        $product = $this->registry->registry('current_product');
        return $product instanceof ProductInterface ? $product : null;
    }

    /**
     * schema.org Question nodes from the `optaeo_faq` attribute, or [] when the product
     * has no usable FAQ. Mirrors the connector's canonicaliser
     * (app/lib/writeback/magento-faq.ts) so the questions we SERVE are exactly the ones
     * OptAEO stored: same caps, same plain-text stripping, same drop-a-half-entry rule.
     *
     * Never throws and never fabricates: malformed JSON, a non-array payload, or entries
     * missing a question or an answer all yield [] (=> no FAQ node emitted).
     *
     * @return array<int, array<string, mixed>>
     */
    private function resolveFaq(ProductInterface $product): array
    {
        $raw = trim((string) $product->getData('optaeo_faq'));
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $q = $this->cleanFaqText($entry['q'] ?? null, 300);
            $a = $this->cleanFaqText($entry['a'] ?? null, 2000);
            if ($q === '' || $a === '') {
                continue; // never a half-entry
            }
            $out[] = [
                '@type' => 'Question',
                'name' => $q,
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a],
            ];
            if (count($out) >= 20) {
                break;
            }
        }
        return $out;
    }

    /** Plain text, collapsed whitespace, capped — matches the TS `clean()` helper. */
    private function cleanFaqText(mixed $value, int $cap): string
    {
        if (!is_string($value)) {
            return '';
        }
        $text = strip_tags($value);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        return mb_substr($text, 0, $cap);
    }

    /**
     * Cap on emitted OfferShippingDetails entries. Mirrors lib/magento/shipping.ts
     * MAX_DESTINATIONS (25) so the served node never lists more destinations than the
     * connector itself credits — and an oversized offer blob never gets truncated by a
     * crawler.
     */
    private const SHIPPING_DEST_MAX = 25;

    /**
     * schema.org OfferShippingDetails[] composed from the LIVE store shipping config, or
     * [] when there is genuinely nothing to represent (no destinations AND no free
     * shipping, or the config read fails). Mirrors the connector's shape exactly
     * (main repo: app/lib/remediation/engine.ts mergeShippingOfferDetailsIntoProductJsonLd):
     *   - one entry per allowed destination country (DefinedRegion / addressCountry);
     *   - NO deliveryTime — Magento core has no transit/EDD data, so we never fabricate it;
     *   - shippingRate MonetaryAmount value 0 ONLY when free shipping is actually configured.
     *
     * Never throws (one config hiccup must not cost the whole node).
     *
     * @return array<int, array<string, mixed>>
     */
    private function resolveShippingDetails(): array
    {
        try {
            $cfg = $this->shippingConfigManagement->getConfig();
        } catch (\Throwable $e) {
            return []; // config unreadable → honest omit, never break the node
        }

        $free = $cfg->getHasFreeShipping();
        $carrierCount = $cfg->getCarrierCount();

        // No carriers AND no free shipping ⇒ no shipping to represent (mirrors the
        // connector writing 0 rows). Honest omission, not an empty node.
        if ($carrierCount === 0 && !$free) {
            return [];
        }

        $currency = trim((string) $cfg->getCurrency()) ?: 'USD';
        $rate = $free
            ? ['@type' => 'MonetaryAmount', 'value' => 0, 'currency' => $currency]
            : null;

        $countries = [];
        foreach ($cfg->getAllowedCountries() as $c) {
            $code = strtoupper(trim((string) $c));
            if ($code === '') {
                continue;
            }
            $countries[] = substr($code, 0, 2);
            if (count($countries) >= self::SHIPPING_DEST_MAX) {
                break;
            }
        }

        // No enumerable destinations. If shipping is free, a single bare
        // OfferShippingDetails (no destination = "ships broadly") is the honest
        // representation — matching the connector's Rest-of-World handling. Otherwise
        // there is nothing store-truthful to say.
        if ($countries === []) {
            return $rate !== null
                ? [['@type' => 'OfferShippingDetails', 'shippingRate' => $rate]]
                : [];
        }

        $details = [];
        foreach ($countries as $code) {
            $detail = [
                '@type' => 'OfferShippingDetails',
                'shippingDestination' => [
                    '@type' => 'DefinedRegion',
                    'addressCountry' => $code,
                ],
            ];
            if ($rate !== null) {
                $detail['shippingRate'] = $rate;
            }
            $details[] = $detail;
        }
        return $details;
    }

    /**
     * Codes that are NEVER a generic descriptive attribute, even if flagged
     * user-defined — they map to dedicated schema fields (name/brand/gtin/description),
     * are SEO/url/media system slots, or are OptAEO's own delivery slots. MIRRORS the
     * connector's MAGENTO_NON_DESCRIPTIVE_CODES (main repo:
     * lib/connectors/magento-attribute-eligibility.ts) so "what is a real descriptive
     * attribute" has ONE definition across the connector and this module.
     */
    private const NON_DESCRIPTIVE_CODES = [
        'gtin', 'upc', 'ean', 'brand', 'manufacturer',
        'optaeo_faq',
        'description', 'short_description',
        'meta_title', 'meta_description', 'meta_keyword', 'url_key', 'url_path',
        'image', 'small_image', 'thumbnail', 'swatch_image',
        'image_label', 'small_image_label', 'thumbnail_label',
        'cost',
        'old_id', 'name', 'sku',
    ];

    /**
     * Cap on emitted spec properties. Real products carry a handful, so this only bounds
     * a pathological import — an oversized JSON-LD blob is slower to fetch and likelier
     * to be truncated by a crawler, which would COST visibility rather than add it.
     * Matches the connector-side cap (app/lib/remediation/schema-org.ts SPEC_ATTR_MAX).
     */
    private const SPEC_ATTR_MAX = 30;

    /**
     * Descriptive EAV attributes as schema.org PropertyValue entries.
     *
     * Eligibility mirrors the connector's isDescriptiveUserAttribute: user-defined AND
     * not a price input, minus NON_DESCRIPTIVE_CODES. Values are resolved through
     * getAttributeText() first so select/multiselect emit their LABELS rather than the
     * stored option IDs. Empty values are omitted entirely — a half-filled attribute
     * must never become a hollow public claim.
     *
     * @return array<int, array<string, string>>
     */
    private function resolveSpecProperties(ProductInterface $product): array
    {
        $out = [];
        try {
            /** @var \Magento\Catalog\Model\Product $product */
            $attributes = $product->getAttributes();
        } catch (\Throwable $e) {
            return []; // never break the node over spec attributes
        }

        foreach ($attributes as $attribute) {
            if (count($out) >= self::SPEC_ATTR_MAX) {
                break;
            }
            try {
                if (!$attribute->getIsUserDefined()) {
                    continue;
                }
                if ($attribute->getFrontendInput() === 'price') {
                    continue;
                }
                $code = (string) $attribute->getAttributeCode();
                if ($code === '' || in_array($code, self::NON_DESCRIPTIVE_CODES, true)) {
                    continue;
                }

                // Labels for select/multiselect; raw data for text/textarea.
                $value = $product->getAttributeText($code);
                if (is_array($value)) {
                    $value = implode(', ', array_filter(array_map('strval', $value), static fn($v) => trim($v) !== ''));
                }
                if (!is_string($value) || trim($value) === '') {
                    $value = $product->getData($code);
                }
                if (is_array($value) || is_object($value) || is_bool($value)) {
                    continue;
                }
                $value = trim((string) $value);
                if ($value === '' || $value === '[Requires input]') {
                    continue;
                }

                $label = trim((string) $attribute->getDefaultFrontendLabel());
                $out[] = [
                    '@type' => 'PropertyValue',
                    'propertyID' => $code,
                    'name' => $label !== '' ? $label : $code,
                    'value' => $value,
                ];
            } catch (\Throwable $e) {
                continue; // one bad attribute never costs the rest
            }
        }

        return $out;
    }

    private function resolveBrand(ProductInterface $product): ?string
    {
        $brand = trim((string) $product->getData('brand'));
        if ($brand !== '') {
            return $brand;
        }
        try {
            /** @var \Magento\Catalog\Model\Product $product */
            $mfr = $product->getAttributeText('manufacturer');
            if (is_array($mfr)) {
                $mfr = implode(', ', $mfr);
            }
            $mfr = trim((string) $mfr);
            if ($mfr !== '') {
                return $mfr;
            }
        } catch (\Throwable $e) {
            // no manufacturer attribute → honest omit
        }
        return null;
    }

    /** @return string[] absolute URLs of enabled gallery images */
    private function resolveImages(ProductInterface $product): array
    {
        $urls = [];
        try {
            /** @var \Magento\Catalog\Model\Product $product */
            $gallery = $product->getMediaGalleryImages();
            if ($gallery) {
                foreach ($gallery as $image) {
                    if ((int) $image->getData('disabled') === 1) {
                        continue;
                    }
                    $u = (string) $image->getUrl();
                    if ($u !== '') {
                        $urls[] = $u;
                    }
                }
            }
        } catch (\Throwable $e) {
            // gallery not loaded → honest omit
        }
        return $urls;
    }

    private function resolveCategory(ProductInterface $product): ?string
    {
        try {
            /** @var \Magento\Catalog\Model\Product $product */
            $collection = $product->getCategoryCollection()->addAttributeToSelect('name')->setPageSize(1);
            $name = trim((string) $collection->getFirstItem()->getName());
            return $name !== '' ? $name : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function resolvePrice(ProductInterface $product): float
    {
        try {
            /** @var \Magento\Catalog\Model\Product $product */
            return (float) $product->getPriceInfo()->getPrice('final_price')->getValue();
        } catch (\Throwable $e) {
            return (float) $product->getData('price');
        }
    }
}
