<?php
/**
 * The one seam between the callback dispatcher and the network. The production
 * implementation is CurlTransport; unit tests substitute a fake so the dispatch,
 * dedupe, budget, and logging rules are testable without sockets.
 */
declare(strict_types=1);

namespace Optaeo\Aeo\Model\Webhook;

interface HttpTransportInterface
{
    /**
     * POST the signed request and WAIT for the response (bounded). Must never
     * throw: every failure mode is reported inside the TransportResult.
     *
     * @param SignedRequest $request
     * @param int $connectTimeoutMs Budget for DNS + TCP + TLS.
     * @param int $totalTimeoutMs Budget for the whole exchange, including the response.
     * @return TransportResult
     */
    public function post(SignedRequest $request, int $connectTimeoutMs, int $totalTimeoutMs): TransportResult;
}
