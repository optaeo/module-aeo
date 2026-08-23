<?php
/**
 * Production RuntimeInterface backed by the PHP SAPI.
 */
declare(strict_types=1);

namespace Optaeo\Aeo\Model\Webhook;

class PhpRuntime implements RuntimeInterface
{
    public function canFinishRequestEarly(): bool
    {
        return !$this->isCli()
            && (function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request'));
    }

    public function isCli(): bool
    {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }

    public function onShutdown(callable $callback): void
    {
        register_shutdown_function($callback);
    }

    public function finishRequest(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            // Flushes headers + buffered body to nginx/Apache and closes the client
            // connection; the merchant's save has returned by the time this returns.
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        // The session module writes + unlocks the session in its own shutdown step,
        // AFTER user shutdown functions — so without this a delivery that waits on
        // the network would keep the admin session locked and stall the merchant's
        // next click. Release it now: the response is already out, nothing after
        // this point needs the session.
        if (session_status() === PHP_SESSION_ACTIVE) {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            session_write_close();
        }
    }

    public function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }
}
