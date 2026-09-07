<?php
/**
 * Maps the ROOT paths /llms.txt, /agents.txt, /agents.md, /sitemap.xml — and the
 * provisioned IndexNow key file /<key>.txt — onto the OptAEO protocol controllers.
 * Magento frontNames live under a path segment (/optaeo/...); these protocol files
 * must serve at the bare store root, so a custom router resolves the matching
 * controller/action and returns the action instance DIRECTLY — the exact pattern
 * Magento_Robots uses for /robots.txt (returning the action avoids the Forward
 * re-routing loop, since this router would otherwise re-match the unchanged path).
 */
declare(strict_types=1);

namespace Optaeo\Aeo\Controller\Router;

use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Route\ConfigInterface;
use Magento\Framework\App\Router\ActionList;
use Magento\Framework\App\RouterInterface;
use Optaeo\Aeo\Model\ProtocolSettings;

class ProtocolRouter implements RouterInterface
{
    /** root path => action name on Optaeo\Aeo\Controller\Protocol (frontName 'optaeo', controller 'protocol') */
    private const ROUTES = [
        'llms.txt' => 'llms',
        // agents.txt is the canonical crawler-policy URL used by OptAEO's
        // crawlability checks and readiness score. Keep agents.md as a compatible
        // discovery-document alias for platforms that already link to it.
        'agents.txt' => 'agents',
        'agents.md' => 'agents',
        'sitemap.xml' => 'sitemap',
    ];

    public function __construct(
        private readonly ActionFactory $actionFactory,
        private readonly ActionList $actionList,
        private readonly ConfigInterface $routeConfig,
        private readonly ProtocolSettings $settings
    ) {
    }

    public function match(RequestInterface $request): ?ActionInterface
    {
        $identifier = trim((string) $request->getPathInfo(), '/');
        $action = self::ROUTES[$identifier] ?? null;
        if ($action === null && preg_match('/^sitemap-[1-9][0-9]*\.xml$/D', $identifier) === 1) {
            $action = 'sitemap';
        }
        if ($action === null) {
            // /<key>.txt — ONLY the exact provisioned IndexNow key, case-sensitive.
            // Every other /<something>.txt falls through to the standard routers.
            $key = $this->settings->getIndexNowKey();
            if ($key !== '' && $identifier === $key . '.txt') {
                $action = 'indexnowkey';
            }
        }
        if ($action === null) {
            return null;
        }
        $modules = $this->routeConfig->getModulesByFrontName('optaeo');
        if (empty($modules)) {
            return null;
        }
        $actionClassName = $this->actionList->get($modules[0], null, 'protocol', $action);
        if (!$actionClassName) {
            return null;
        }
        return $this->actionFactory->create($actionClassName);
    }
}
