<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Http\Exception;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Throw from a handler or middleware to send an error status, for example:
 *
 *     throw new HttpException(402, 'Payment required for this report.');
 *
 * The message appears in the response only when APP_DEBUG=true; otherwise clients see the
 * standard reason phrase. Subclasses cover the common statuses.
 */
class HttpException extends RuntimeException implements HttpExceptionInterface
{
    /** @param array<string, string|list<string>> $headers */
    public function __construct(
        private readonly int $statusCode,
        string $message = '',
        ?Throwable $previous = null,
        private readonly array $headers = [],
        int $code = 0,
    ) {
        if ($statusCode < 400 || $statusCode > 599) {
            throw new InvalidArgumentException("HTTP exceptions need an error status (400-599), not {$statusCode}.");
        }
        parent::__construct($message, $code, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }
}
