<?php
declare(strict_types=1);

namespace Optaeo\Aeo\Controller\Protocol;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Optaeo\Aeo\Model\ProtocolContent;
use Optaeo\Aeo\Model\ProtocolSettings;

/**
 * Serves the curated, catalogue-aware /agents.txt (and the /agents.md alias) at the
 * store root (text/markdown) — or an honest 404 when the merchant has switched
 * agents.txt off in OptAEO (the connector pushes that switch here; see
 * ProtocolSettings), so the store never serves a file OptAEO says is off.
 */
class Agents implements HttpGetActionInterface
{
    public function __construct(
        private readonly RawFactory $rawFactory,
        private readonly ProtocolContent $protocolContent,
        private readonly ProtocolSettings $settings
    ) {
    }

    public function execute(): Raw
    {
        /** @var Raw $result */
        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', 'text/markdown; charset=utf-8', true);
        if (!$this->settings->isAgentsTxtEnabled()) {
            $result->setHttpResponseCode(404);
            $result->setContents("agents.txt is switched off for this store.\n");
            return $result;
        }
        $result->setContents($this->protocolContent->agentsMd());
        return $result;
    }
}
