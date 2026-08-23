<?php
/**
 * cURL delivery of the signed callback: writes the whole request, then WAITS for
 * the response within a bounded timeout, and reports what happened.
 *
 * Why not the raw stream socket the module shipped in 1.1.0: on a TLS 1.3 edge
 * (verified against the OptAEO receiver's edge with tcpdump) the server appends a
 * post-handshake record to its handshake flight; OpenSSL leaves it unread in the
 * kernel buffer, Nagle holds the small request behind the still-unacknowledged
 * client Finished, and closing the socket with unread inbound bytes makes the kernel
 * send RST and DISCARD the queued request. Every callback completed the TLS
 * handshake and never sent one byte of HTTP. The cure is structural: read the
 * response before closing (this class), and never close early (the dispatcher).
 *
 * Why raw curl_* and not \Magento\Framework\HTTP\Client\Curl: that client reports
 * the FIRST status line it sees, so an interim "100 Continue" is returned as the
 * result status; CURLINFO_RESPONSE_CODE is the final status. Direct cURL calls are
 * flagged as "discouraged" by the Magento coding standard, hence the annotations.
 */
declare(strict_types=1);

namespace Optaeo\Aeo\Model\Webhook;

class CurlTransport implements HttpTransportInterface
{
    private const BODY_KEEP_BYTES = 2048;

    public function post(SignedRequest $request, int $connectTimeoutMs, int $totalTimeoutMs): TransportResult
    {
        $started = microtime(true);
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $ch = curl_init();
        if ($ch === false) {
            return new TransportResult(null, '', false, 'curl_init failed', 0, 0);
        }
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        curl_setopt_array($ch, [
            CURLOPT_URL => $request->url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $request->body,
            CURLOPT_HTTPHEADER => $request->headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => max(1, $connectTimeoutMs),
            CURLOPT_TIMEOUT_MS => max(1, $totalTimeoutMs),
            // Millisecond timeouts need NOSIGNAL so cURL does not arm SIGALRM in a
            // long-lived FPM worker (a known source of spurious "timeout" errors).
            CURLOPT_NOSIGNAL => true,
            // A signed body must never be replayed to a redirect target.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADER => false,
        ]);
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $rawBody = curl_exec($ch);
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $errno = curl_errno($ch);
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $error = $errno !== 0 ? curl_error($ch) : null;
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $uploaded = (float) curl_getinfo($ch, CURLINFO_SIZE_UPLOAD_T);
        // No curl_close(): a no-op since PHP 8.0 and deprecated in 8.5 — the handle
        // is released when it goes out of scope.
        unset($ch);

        $elapsedMs = (int) round((microtime(true) - $started) * 1000);
        $requestSent = $uploaded >= strlen($request->body);
        // A status is only meaningful when the exchange produced a final response;
        // on transport errors cURL reports 0 (or a stale value) — treat as none.
        $httpStatus = ($errno === 0 && $status > 0) ? $status : null;
        $body = is_string($rawBody) ? substr($rawBody, 0, self::BODY_KEEP_BYTES) : '';

        return new TransportResult(
            $httpStatus,
            $body,
            $requestSent,
            $error !== null ? sprintf('%s (curl %d)', $error, $errno) : null,
            $errno,
            $elapsedMs
        );
    }
}
