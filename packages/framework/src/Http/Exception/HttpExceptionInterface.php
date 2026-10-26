<?php

declare(strict_types=1);

namespace Crystoline\Http\Exception;

use Throwable;

/**
 * An exception that maps to an HTTP status code. The exception handler renders it with that
 * status and these headers, and does not log it unless the status is 500 or higher.
 */
interface HttpExceptionInterface extends Throwable
{
    public function getStatusCode(): int;

    /** @return array<string, string|list<string>> */
    public function getHeaders(): array;
}
