<?php
/**
 * One product-save callback the observer wants delivered to OptAEO. Immutable —
 * the observer captures these four strings during the save and hands them to the
 * dispatcher; nothing here references Magento objects, so the event survives to
 * the post-response flush without holding the product in memory.
 */
declare(strict_types=1);

namespace Optaeo\Aeo\Model\Webhook;

class CallbackEvent
{
    public function __construct(
        public readonly string $callbackUrl,
        public readonly string $secret,
        public readonly string $sku,
        public readonly string $storeHost
    ) {
    }

    /** Dedupe key: several saves of the same SKU in one request collapse to one delivery. */
    public function key(): string
    {
        return $this->storeHost . '|' . $this->sku;
    }
}
