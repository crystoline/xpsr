<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Http\Exception;

use Throwable;

/** 415: Unsupported media type. */
class UnsupportedMediaTypeHttpException extends HttpException
{
    /** @param array<string, string|list<string>> $headers */
    public function __construct(string $message = '', ?Throwable $previous = null, array $headers = [], int $code = 0)
    {
        parent::__construct(415, $message, $previous, $headers, $code);
    }
}
