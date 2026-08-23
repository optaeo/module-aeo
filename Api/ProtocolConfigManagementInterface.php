<?php
/**
 * Provisioning endpoint for the store-root protocol files. The OptAEO connector
 * calls PUT /V1/optaeo/protocol-config when the merchant toggles llms.txt /
 * agents.txt in OptAEO (and re-pushes on every sync) so the module serves exactly
 * what OptAEO's compliance page says it serves; the IndexNow key is pushed the same
 * way so the module can host /<key>.txt for search-engine verification.
 * Authorized by the connector's OAuth1 Integration token (ACL Optaeo_Aeo::webhooks —
 * the resource that already covers connector-pushed configuration, so integrations
 * granted for 1.1.0 keep working without a re-grant).
 */
declare(strict_types=1);

namespace Optaeo\Aeo\Api;

interface ProtocolConfigManagementInterface
{
    /**
     * Save the protocol switches. Overwrites all three values (idempotent).
     *
     * @param bool $llmsTxtEnabled Serve /llms.txt at the store root.
     * @param bool $agentsTxtEnabled Serve /agents.txt and /agents.md at the store root.
     * @param string $indexNowKey IndexNow key to host as /<key>.txt (8–128 chars, [A-Za-z0-9-]); '' to host none.
     * @return bool true when saved.
     */
    public function save(bool $llmsTxtEnabled, bool $agentsTxtEnabled, string $indexNowKey = ''): bool;
}
