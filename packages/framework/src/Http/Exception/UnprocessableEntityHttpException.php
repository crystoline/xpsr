<?php

declare(strict_types=1);

namespace Crystoline\Http\Exception;

use Throwable;

/** 422: Unprocessable content. */
class UnprocessableEntityHttpException extends HttpException
{
    /** @param array<string, string|list<string>> $headers */
    public function __construct(string $message = '', ?Throwable $previous = null, array $headers = [], int $code = 0)
    {
        parent::__construct(422, $message, $previous, $headers, $code);
    }
}
