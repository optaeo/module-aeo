<?php
declare(strict_types=1);

namespace Optaeo\Aeo\Controller\Protocol;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Optaeo\Aeo\Model\ProtocolSettings;

/**
 * Hosts the IndexNow verification file at the store root: GET /<key>.txt returns
 * the key as text/plain (the IndexNow ownership contract). The key is provisioned
 * by the OptAEO connector (PUT /V1/optaeo/protocol-config); the root router only
 * dispatches here when the requested filename equals the provisioned key, so no
 * other /<anything>.txt path is ever answered by this controller.
 */
class IndexNowKey implements HttpGetActionInterface
{
    public function __construct(
        private readonly RawFactory $rawFactory,
        private readonly ProtocolSettings $settings
    ) {
    }

    public function execute(): Raw
    {
        /** @var Raw $result */
        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
        $key = $this->settings->getIndexNowKey();
        if ($key === '') {
            $result->setHttpResponseCode(404);
            $result->setContents("No IndexNow key is provisioned for this store.\n");
            return $result;
        }
        $result->setContents($key);
        return $result;
    }
}
