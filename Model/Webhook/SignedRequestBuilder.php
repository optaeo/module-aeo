<?php
/**
 * Builds the signed product-save callback exactly the way the OptAEO receiver
 * (POST /api/webhooks/magento) verifies it:
 *
 *   body      = {"sku": "...", "store_host": "..."}            (compact JSON)
 *   signature = HMAC-SHA256( timestamp . "." . eventId . "." . body , secret )
 *   headers   = X-OptAEO-Webhook-Timestamp / -Id / -Signature: sha256=<hex>
 *
 * The receiver re-derives the per-merchant secret, checks the timestamp is within
 * five minutes, checks the id is 32 hex chars, compares the signature in constant
 * time, and refuses to process an id it has already seen — so every delivery must
 * carry a fresh id and a current timestamp. Pure and side-effect free (timestamp and
 * id are inputs) so the wire contract is unit-testable against a fixed vector.
 */
declare(strict_types=1);

namespace Optaeo\Aeo\Model\Webhook;

class SignedRequestBuilder
{
    public const HEADER_TIMESTAMP = 'X-OptAEO-Webhook-Timestamp';
    public const HEADER_ID = 'X-OptAEO-Webhook-Id';
    public const HEADER_SIGNATURE = 'X-OptAEO-Webhook-Signature';

    public function build(CallbackEvent $event, int $timestamp, string $eventId, string $userAgent): SignedRequest
    {
        $body = (string) json_encode(
            ['sku' => $event->sku, 'store_host' => $event->storeHost],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $signature = hash_hmac('sha256', $timestamp . '.' . $eventId . '.' . $body, $event->secret);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: ' . $userAgent,
            self::HEADER_TIMESTAMP . ': ' . $timestamp,
            self::HEADER_ID . ': ' . $eventId,
            self::HEADER_SIGNATURE . ': sha256=' . $signature,
            // Never let cURL add "Expect: 100-continue" — a proxy that honours it
            // adds a full round-trip (or a 1s stall) to every delivery for no gain
            // on a ~100-byte body.
            'Expect:',
        ];
        return new SignedRequest($event->callbackUrl, $headers, $body, $eventId);
    }

    /** 128 bits of entropy — a delivery identifier, deliberately unique per save. */
    public function newEventId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
