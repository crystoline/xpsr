<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Http\Exception;

use Throwable;

/** 405: Method not allowed. The allowed methods become the Allow header. */
class MethodNotAllowedHttpException extends HttpException
{
    /**
     * @param list<string> $allowedMethods
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(array $allowedMethods, string $message = '', ?Throwable $previous = null, array $headers = [], int $code = 0)
    {
        $allow = implode(', ', array_map('strtoupper', $allowedMethods));
        parent::__construct(405, $message, $previous, ['Allow' => $allow] + $headers, $code);
    }
}
