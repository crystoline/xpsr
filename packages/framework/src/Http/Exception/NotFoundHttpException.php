<?php

declare(strict_types=1);

namespace Crystoline\Http\Exception;

use Throwable;

/** 404: Not found. */
class NotFoundHttpException extends HttpException
{
    /** @param array<string, string|list<string>> $headers */
    public function __construct(string $message = '', ?Throwable $previous = null, array $headers = [], int $code = 0)
    {
        parent::__construct(404, $message, $previous, $headers, $code);
    }
}
