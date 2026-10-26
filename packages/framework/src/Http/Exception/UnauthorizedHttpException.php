<?php

declare(strict_types=1);

namespace Crystoline\Http\Exception;

use Throwable;

/** 401: Unauthorized. The challenge becomes the WWW-Authenticate header, for example 'Bearer realm="api"'. */
class UnauthorizedHttpException extends HttpException
{
    /** @param array<string, string|list<string>> $headers */
    public function __construct(string $challenge, string $message = '', ?Throwable $previous = null, array $headers = [], int $code = 0)
    {
        parent::__construct(401, $message, $previous, ['WWW-Authenticate' => $challenge] + $headers, $code);
    }
}
