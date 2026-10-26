<?php

declare(strict_types=1);

namespace Crystoline\Http;

use Closure;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Sends a PSR-7 response through the PHP SAPI: headers, then the status line, then the body.
 */
final class ResponseEmitter
{
    /** Statuses that never carry a body. */
    private const BODYLESS = [204, 205, 304];

    /** @var Closure(string, bool, int): void */
    private readonly Closure $header;
    /** @var Closure(): ?string */
    private readonly Closure $outputStartedAt;

    /**
     * @param int $chunkSize bytes read from the body per write
     * @param (Closure(string, bool, int): void)|null $header replaces PHP's header() in tests
     * @param (Closure(): ?string)|null $outputStartedAt "file:line" once output has started, otherwise null
     */
    public function __construct(private readonly int $chunkSize = 8192, ?Closure $header = null, ?Closure $outputStartedAt = null)
    {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException('The chunk size must be at least 1 byte.');
        }
        $this->header = $header ?? static function (string $line, bool $replace, int $status): void {
            header($line, $replace, $status);
        };
        $this->outputStartedAt = $outputStartedAt ?? static fn(): ?string => headers_sent($file, $line) ? "{$file}:{$line}" : null;
    }

    /** @throws RuntimeException when output has already started, because headers can no longer be sent */
    public function emit(ResponseInterface $response): void
    {
        $startedAt = ($this->outputStartedAt)();
        if ($startedAt !== null) {
            throw new RuntimeException("Cannot send the response: output already started at {$startedAt}.");
        }

        $status = $response->getStatusCode();
        // Passing the status with every header stops PHP from replacing it, for example with 302 for Location.
        foreach ($response->getHeaders() as $name => $values) {
            $replace = strtolower((string) $name) !== 'set-cookie';
            foreach ($values as $value) {
                ($this->header)("{$name}: {$value}", $replace, $status);
                $replace = false;
            }
        }
        // The status line goes last so that no later header() call can change it.
        ($this->header)(rtrim("HTTP/{$response->getProtocolVersion()} {$status} {$response->getReasonPhrase()}"), true, $status);

        if ($status >= 200 && !in_array($status, self::BODYLESS, true)) {
            $this->emitBody($response);
        }
    }

    private function emitBody(ResponseInterface $response): void
    {
        $body = $response->getBody();
        $streaming = !$body->isSeekable();
        if (!$streaming) {
            $body->rewind();
        }
        // Never send more than a declared Content-Length; clients would misread the next response.
        $length = $response->getHeaderLine('Content-Length');
        $remaining = ctype_digit($length) ? (int) $length : null;

        while (($remaining === null || $remaining > 0) && !$body->eof()) {
            $chunk = $body->read($remaining === null ? $this->chunkSize : min($this->chunkSize, $remaining));
            if ($chunk === '') {
                break;
            }
            echo $chunk;
            if ($remaining !== null) {
                $remaining -= strlen($chunk);
            }
            if ($streaming) {
                // Deliver streamed chunks as they are produced instead of when the script ends.
                flush();
            }
            if (connection_status() !== CONNECTION_NORMAL) {
                break;
            }
        }
    }
}
