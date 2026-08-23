<?php
/**
 * A fully materialised, HMAC-signed HTTP request for the OptAEO receiver — URL,
 * headers, and the exact JSON body the signature covers. Built by
 * SignedRequestBuilder; consumed by an HttpTransportInterface implementation.
 */
declare(strict_types=1);

namespace Optaeo\Aeo\Model\Webhook;

class SignedRequest
{
    /**
     * @param string $url Absolute receiver URL.
     * @param string[] $headers "Name: value" lines, in send order.
     * @param string $body Raw JSON body — the bytes the signature was computed over.
     * @param string $eventId 32-hex delivery id (also sent as X-OptAEO-Webhook-Id).
     */
    public function __construct(
        public readonly string $url,
        public readonly array $headers,
        public readonly string $body,
        public readonly string $eventId
    ) {
    }
}
