<?php
/**
 * What actually happened on the wire for one delivery attempt — enough to log an
 * honest verdict. `requestSent` is the key distinction the old fire-and-forget
 * socket never had: it is true only when the whole request body left this host,
 * so "sent but no answer within the timeout" (OptAEO may well have processed it)
 * is reported differently from "never connected" (nothing was delivered).
 */
declare(strict_types=1);

namespace Optaeo\Aeo\Model\Webhook;

class TransportResult
{
    public function __construct(
        /** HTTP status of the FINAL response (interim 1xx ignored); null when none was received. */
        public readonly ?int $httpStatus,
        /** Response body, truncated to 2 KB; empty when none. */
        public readonly string $body,
        /** True when every byte of the request body was written to the network. */
        public readonly bool $requestSent,
        /** Transport-level error (DNS / connect / TLS / timeout); null when the exchange completed. */
        public readonly ?string $error,
        /** Transport-level error code (cURL errno); 0 when none. */
        public readonly int $errorCode,
        public readonly int $elapsedMs
    ) {
    }

    /** The receiver answered with a success status. */
    public function isDelivered(): bool
    {
        return $this->httpStatus !== null && $this->httpStatus >= 200 && $this->httpStatus < 300;
    }

    /**
     * Nothing reached OptAEO at all (DNS, refused, TLS, or a timeout that struck
     * before the body was written). The dispatcher stops a flush on the first of
     * these — an unreachable OptAEO must not cost the store one timeout per event.
     */
    public function isConnectFailure(): bool
    {
        return $this->httpStatus === null && !$this->requestSent;
    }
}
