<?php

declare(strict_types=1);

namespace Crystoline\Http\Exception;

use Throwable;

/** 429: Too many requests. A retry delay in seconds becomes the Retry-After header. */
class TooManyRequestsHttpException extends HttpException
{
    /** @param array<string, string|list<string>> $headers */
    public function __construct(?int $retryAfterSeconds = null, string $message = '', ?Throwable $previous = null, array $headers = [], int $code = 0)
    {
        if ($retryAfterSeconds !== null) {
            $headers = ['Retry-After' => (string) max(0, $retryAfterSeconds)] + $headers;
        }
        parent::__construct(429, $message, $previous, $headers, $code);
    }
}
