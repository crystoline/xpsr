<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Http\Exception;

use Throwable;

/** 400: Bad request. */
class BadRequestHttpException extends HttpException
{
    /** @param array<string, string|list<string>> $headers */
    public function __construct(string $message = '', ?Throwable $previous = null, array $headers = [], int $code = 0)
    {
        parent::__construct(400, $message, $previous, $headers, $code);
    }
}
