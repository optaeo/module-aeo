<?php
/**
 * Reads store-level shipping configuration server-side (core Magento exposes none
 * over REST) for the OptAEO shipping-readiness pull. Everything is read at WEBSITE
 * scope, which auto-falls-back to the default/global value when a website override
 * isn't set.
 *
 * HONEST has_free_shipping — credited from one of, never fabricated:
 *   (a) the offline free-shipping CARRIER: carriers/freeshipping/active = 1 AND a
 *       usable carriers/freeshipping/free_shipping_subtotal threshold; OR
 *   (b) an automatic cart price rule: is_active = 1, simple_free_shipping ∈ {1,2},
 *       coupon_type = NO_COUPON (automatic), in date window, applies to this website
 *       and the guest customer group (0). Coupon-gated rules (SPECIFIC/AUTO) are NOT
 *       credited — a shopper would need a code, so it isn't unconditional free shipping.
 *
 * transit_days are ALWAYS null: Magento core carries no transit/EDD data anywhere,
 * so we never invent it (the scorer's (transit<=5) OR free rule lets free shipping
 * earn the pass without transit — the same merchant-level shape as BC/Woo).
 */
declare(strict_types=1);

namespace Optaeo\Aeo\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\SalesRule\Api\Data\ConditionInterface;
use Magento\SalesRule\Api\Data\RuleInterface;
use Magento\SalesRule\Api\RuleRepositoryInterface;
use Magento\Shipping\Model\Config as ShippingCarrierConfig;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Optaeo\Aeo\Api\Data\ShippingCarrierInterfaceFactory;
use Optaeo\Aeo\Api\Data\ShippingConfigInterface;
use Optaeo\Aeo\Api\Data\ShippingConfigInterfaceFactory;
use Optaeo\Aeo\Api\ShippingConfigManagementInterface;

class ShippingConfigManagement implements ShippingConfigManagementInterface
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ShippingCarrierConfig $shippingConfig,
        private readonly RuleRepositoryInterface $ruleRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly ResourceConnection $resource,
        private readonly ShippingConfigInterfaceFactory $configFactory,
        private readonly ShippingCarrierInterfaceFactory $carrierFactory
    ) {
    }

    public function getConfig(): ShippingConfigInterface
    {
        $store = $this->storeManager->getStore();
        $storeId = (int) $store->getId();
        $websiteId = (int) $store->getWebsiteId();
        $currency = (string) $store->getBaseCurrencyCode();
        $scope = ScopeInterface::SCOPE_WEBSITE;

        // ── active carriers ──
        $carriers = [];
        $carrierCodes = [];
        foreach ($this->shippingConfig->getActiveCarriers($storeId) as $code => $carrier) {
            $code = (string) $code;
            $carrierCodes[] = $code;
            $title = (string) ($this->scopeConfig->getValue("carriers/{$code}/title", $scope, $websiteId)
                ?: $carrier->getConfigData('title')
                ?: $code);
            $carriers[] = $this->carrierFactory->create()->setCode($code)->setTitle($title);
        }

        // ── free shipping (a) offline carrier ──
        $hasFree = false;
        $freeThreshold = null;
        $freeSource = null;
        $freeActive = $this->scopeConfig->isSetFlag('carriers/freeshipping/active', $scope, $websiteId);
        if ($freeActive) {
            $sub = $this->scopeConfig->getValue('carriers/freeshipping/free_shipping_subtotal', $scope, $websiteId);
            // An active free-shipping carrier offers free shipping above the subtotal
            // (0/empty subtotal = free shipping with no minimum — still free).
            $hasFree = true;
            $freeSource = 'offline_carrier';
            $freeThreshold = ($sub !== null && $sub !== '' && (float) $sub > 0) ? (float) $sub : null;
        }

        // ── free shipping (b) automatic cart price rule (only if not already free) ──
        if (!$hasFree) {
            $rule = $this->findAutomaticFreeShippingRule($websiteId);
            if ($rule !== null) {
                $hasFree = true;
                $freeSource = 'cart_price_rule';
                $freeThreshold = $rule; // threshold (float) or null (no-minimum rule)
                if ($freeThreshold === 0.0) {
                    $freeThreshold = null;
                }
            }
        }

        // ── flat rate ──
        $flatRatePrice = null;
        if ($this->scopeConfig->isSetFlag('carriers/flatrate/active', $scope, $websiteId)) {
            $p = $this->scopeConfig->getValue('carriers/flatrate/price', $scope, $websiteId);
            $flatRatePrice = ($p !== null && $p !== '') ? (float) $p : null;
        }

        // ── table rate (informational; defensive — null on any read issue) ──
        [$trCondition, $trMin, $trMax] = $this->readTablerate($websiteId);

        // ── origin ──
        $origin = $this->scopeConfig->getValue('shipping/origin/country_id', $scope, $websiteId);
        $origin = $origin ? (string) $origin : null;

        // ── allowed destination countries ──
        $allowed = $this->resolveAllowedCountries($websiteId, $scope, $carrierCodes);

        /** @var ShippingConfigInterface $cfg */
        $cfg = $this->configFactory->create();
        $cfg->setHasFreeShipping($hasFree)
            ->setFreeShippingThreshold($freeThreshold)
            ->setFreeShippingThresholdCurrency($hasFree && $freeThreshold !== null ? $currency : null)
            ->setFreeShippingSource($freeSource)
            ->setActiveCarriers($carriers)
            ->setCarrierCount(count($carriers))
            ->setFlatRatePrice($flatRatePrice)
            ->setTablerateCondition($trCondition)
            ->setTableratePriceMin($trMin)
            ->setTableratePriceMax($trMax)
            ->setOriginCountry($origin)
            ->setAllowedCountries($allowed)
            ->setTransitDaysMin(null) // Magento core has NO transit data — never fabricated.
            ->setTransitDaysMax(null)
            ->setCurrency($currency)
            ->setWebsiteId($websiteId);
        return $cfg;
    }

    /**
     * Returns the recovered subtotal threshold (float, 0.0 = no minimum) of the FIRST
     * automatic free-shipping cart price rule that applies to this website + guests,
     * or null if none. Wrapped defensively — any read/parse uncertainty => null
     * (we never credit free shipping we can't confirm).
     */
    private function findAutomaticFreeShippingRule(int $websiteId): ?float
    {
        try {
            $today = (new \DateTimeImmutable('now'))->format('Y-m-d');
            $items = $this->ruleRepository->getList($this->searchCriteriaBuilder->create())->getItems();
            foreach ($items as $rule) {
                if (!($rule instanceof RuleInterface)) {
                    continue;
                }
                if (!$rule->getIsActive()) {
                    continue;
                }
                if (!in_array((int) $rule->getSimpleFreeShipping(), [1, 2], true)) {
                    continue;
                }
                $ct = $rule->getCouponType();
                // Data API may return the string NO_COUPON or the legacy int 1.
                $isNoCoupon = $ct === RuleInterface::COUPON_TYPE_NO_COUPON
                    || (string) $ct === '1'
                    || strtoupper((string) $ct) === 'NO_COUPON';
                if (!$isNoCoupon) {
                    continue;
                }
                $from = $rule->getFromDate();
                $to = $rule->getToDate();
                if ($from && substr((string) $from, 0, 10) > $today) {
                    continue;
                }
                if ($to && substr((string) $to, 0, 10) < $today) {
                    continue;
                }
                $websites = array_map('intval', $rule->getWebsiteIds() ?? []);
                if (!in_array($websiteId, $websites, true)) {
                    continue;
                }
                $groups = array_map('intval', $rule->getCustomerGroupIds() ?? []);
                if (!in_array(0, $groups, true)) { // 0 = NOT_LOGGED_IN (guests)
                    continue;
                }
                // Qualifies. Recover the subtotal threshold from the condition tree
                // (0.0 when the rule has no subtotal minimum — still unconditional free).
                return $this->extractSubtotalThreshold($rule->getCondition());
            }
        } catch (\Throwable $e) {
            // Uncertain => do not credit (honest).
            return null;
        }
        return null;
    }

    /**
     * Walks the rule's condition tree for a base_subtotal/subtotal `>=` leaf and
     * returns its value. 0.0 when none found (rule fires with no subtotal minimum).
     */
    private function extractSubtotalThreshold(?ConditionInterface $cond): float
    {
        if ($cond === null) {
            return 0.0;
        }
        $attr = strtolower((string) $cond->getAttributeName());
        $op = (string) $cond->getOperator();
        if (in_array($attr, ['base_subtotal', 'subtotal', 'base_subtotal_total_incl_tax', 'base_subtotal_with_discount'], true)
            && in_array($op, ['>=', '>'], true)
        ) {
            $val = $cond->getValue();
            if (is_numeric($val)) {
                return (float) $val;
            }
        }
        foreach ($cond->getConditions() ?? [] as $child) {
            $t = $this->extractSubtotalThreshold($child);
            if ($t > 0.0) {
                return $t;
            }
        }
        return 0.0;
    }

    /**
     * @return array{0: ?string, 1: ?float, 2: ?float} [condition_name, price_min, price_max]
     */
    private function readTablerate(int $websiteId): array
    {
        try {
            $conn = $this->resource->getConnection();
            $table = $this->resource->getTableName('shipping_tablerate');
            $select = $conn->select()
                ->from($table, ['condition_name', 'price_min' => new \Zend_Db_Expr('MIN(price)'), 'price_max' => new \Zend_Db_Expr('MAX(price)')])
                ->where('website_id IN (?)', [0, $websiteId])
                ->group('condition_name')
                ->limit(1);
            $row = $conn->fetchRow($select);
            if ($row && isset($row['condition_name'])) {
                return [
                    (string) $row['condition_name'],
                    $row['price_min'] !== null ? (float) $row['price_min'] : null,
                    $row['price_max'] !== null ? (float) $row['price_max'] : null,
                ];
            }
        } catch (\Throwable $e) {
            // table absent / column mismatch — informational field, degrade to null.
        }
        return [null, null, null];
    }

    /**
     * Allowed destination ISO-2 countries: general/country/allow, narrowed by the
     * active carriers' specific-country settings. If any active carrier delivers to
     * all allowed countries (sallowspecific=0) the full allow-list stands; otherwise
     * the union of the specific-country carriers (∩ allow-list), falling back to the
     * allow-list when that resolves empty.
     *
     * @param string[] $carrierCodes
     * @return string[]
     */
    private function resolveAllowedCountries(int $websiteId, string $scope, array $carrierCodes): array
    {
        $allow = $this->splitCsv((string) $this->scopeConfig->getValue('general/country/allow', $scope, $websiteId));
        if (empty($carrierCodes)) {
            return $allow;
        }
        $anyAll = false;
        $specific = [];
        foreach ($carrierCodes as $code) {
            if ($this->scopeConfig->isSetFlag("carriers/{$code}/sallowspecific", $scope, $websiteId)) {
                foreach ($this->splitCsv((string) $this->scopeConfig->getValue("carriers/{$code}/specificcountry", $scope, $websiteId)) as $c) {
                    $specific[$c] = true;
                }
            } else {
                $anyAll = true;
            }
        }
        if ($anyAll || empty($specific)) {
            return $allow;
        }
        $intersect = array_values(array_filter($allow, static fn ($c) => isset($specific[$c])));
        return empty($intersect) ? $allow : $intersect;
    }

    /** @return string[] */
    private function splitCsv(string $csv): array
    {
        $out = [];
        foreach (explode(',', $csv) as $p) {
            $p = strtoupper(trim($p));
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return array_values(array_unique($out));
    }
}
