<?php
declare(strict_types=1);

namespace Optaeo\Aeo\Controller\Protocol;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Optaeo\Aeo\Model\ProtocolContent;

/** Serves the complete live enabled-and-visible catalogue as streamed sitemap XML. */
class Sitemap implements HttpGetActionInterface
{
    public function __construct(
        private readonly SitemapStreamResponse $response,
        private readonly ProtocolContent $protocolContent,
        private readonly HttpRequest $request
    ) {
    }

    public function execute(): SitemapStreamResponse
    {
        $page = null;
        $identifier = trim((string) $this->request->getPathInfo(), '/');
        if (preg_match('/^sitemap-([1-9][0-9]*)\.xml$/D', $identifier, $matches) === 1) {
            $page = (int) $matches[1];
        }

        try {
            // Validate the base URL and execute both catalogue count queries
            // before a successful response can expose XML headers. Row reads
            // remain lazy and bounded inside sitemapChunks().
            $truth = $this->protocolContent->prepareSitemapTruth();
        } catch (\Throwable $error) {
            $this->response->clearBody();
            $this->response->setHttpResponseCode(503);
            $this->response->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
            $this->response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
            $this->response->setChunks([]);
            return $this->response;
        }

        $this->response->clearBody();
        $this->response->setHttpResponseCode(200);
        $this->response->setHeader('Content-Type', 'application/xml; charset=utf-8', true);
        $this->response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        $this->response->setChunks(
            $this->request->isHead() ? [] : $this->protocolContent->sitemapChunks($page, $truth)
        );
        return $this->response;
    }
}
