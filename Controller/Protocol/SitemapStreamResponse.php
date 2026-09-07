<?php
declare(strict_types=1);

namespace Optaeo\Aeo\Controller\Protocol;

use Magento\Framework\App\Response\Http as HttpResponse;

/**
 * Streams XML only when Magento sends the response. Chunks are coalesced to keep
 * writes efficient while bounding PHP memory independently of catalogue size.
 * Any catalogue exception propagates, leaving the XML visibly incomplete.
 */
class SitemapStreamResponse extends HttpResponse
{
    private const OUTPUT_BUFFER_BYTES = 65536;

    /** @var iterable<string> */
    private iterable $chunks = [];

    /** @param iterable<string> $chunks */
    public function setChunks(iterable $chunks): self
    {
        $this->chunks = $chunks;
        return $this;
    }

    public function sendResponse(): void
    {
        $this->sendHeaders();
        $buffer = '';
        try {
            foreach ($this->chunks as $chunk) {
                $buffer .= $chunk;
                if (strlen($buffer) < self::OUTPUT_BUFFER_BYTES) {
                    continue;
                }
                echo $buffer;
                $buffer = '';
                $this->flushOutput();
            }
            if ($buffer !== '') {
                echo $buffer;
                $this->flushOutput();
            }
        } finally {
            $this->chunks = [];
        }
    }

    protected function flushOutput(): void
    {
        $bufferStatus = ob_get_status();
        if ($bufferStatus && (($bufferStatus['flags'] ?? 0) & PHP_OUTPUT_HANDLER_FLUSHABLE)) {
            ob_flush();
        }
        flush();
    }
}
