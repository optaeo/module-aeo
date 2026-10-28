<?php
declare(strict_types=1);

namespace Optaeo\Aeo\Api\Data;

/** Native state of the one global OptAEO FAQ slot; not a publication claim. */
interface FaqRestorationInterface
{
    /** @return string Exact requested product SKU. */
    public function getSku(): string;

    /** @return string empty, present, restored, conflict, disabled or unsupported. */
    public function getStatus(): string;

    /** @return string Actual base-scope value; empty when absent or not observable. */
    public function getValue(): string;
}
