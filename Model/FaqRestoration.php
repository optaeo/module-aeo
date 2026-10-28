<?php
declare(strict_types=1);

namespace Optaeo\Aeo\Model;

use Magento\Framework\DataObject;
use Optaeo\Aeo\Api\Data\FaqRestorationInterface;

class FaqRestoration extends DataObject implements FaqRestorationInterface
{
    public function getSku(): string { return (string) $this->getData('sku'); }
    public function getStatus(): string { return (string) $this->getData('status'); }
    public function getValue(): string { return (string) $this->getData('value'); }
}
