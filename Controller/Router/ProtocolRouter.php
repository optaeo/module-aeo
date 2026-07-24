<?php
/**
 * Maps the ROOT paths /llms.txt, /agents.txt, and /agents.md onto the OptAEO
 * protocol controllers.
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
    ];

    public function __construct(
        private readonly ActionFactory $actionFactory,
        private readonly ActionList $actionList,
        private readonly ConfigInterface $routeConfig
    ) {
    }

    public function match(RequestInterface $request): ?ActionInterface
    {
        $identifier = trim((string) $request->getPathInfo(), '/');
        $action = self::ROUTES[$identifier] ?? null;
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
