<?php
declare(strict_types=1);

namespace Optaeo\Aeo\Api;

use Optaeo\Aeo\Api\Data\ShippingConfigInterface;

/**
 * Read-only store-level shipping-config service over REST for the OptAEO connector's
 * OAuth1 Integration token. Core Magento has no shipping-config REST — this is the
 * gap-fill that feeds the shipping-readiness factor.
 */
interface ShippingConfigManagementInterface
{
    /**
     * Store-level shipping configuration for the current website (auto-fallback to default).
     *
     * @return \Optaeo\Aeo\Api\Data\ShippingConfigInterface
     */
    public function getConfig(): ShippingConfigInterface;
}
