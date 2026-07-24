<?php
declare(strict_types=1);

namespace Optaeo\Aeo\Controller\Protocol;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Optaeo\Aeo\Model\ProtocolContent;

/** Serves the curated, catalogue-aware /llms.txt at the store root (text/plain). */
class Llms implements HttpGetActionInterface
{
    public function __construct(
        private readonly RawFactory $rawFactory,
        private readonly ProtocolContent $protocolContent
    ) {
    }

    public function execute(): Raw
    {
        /** @var Raw $result */
        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
        $result->setContents($this->protocolContent->llmsTxt());
        return $result;
    }
}
