<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Http\Exception;

use Throwable;

/**
 * An error whose message and details are meant for the client, such as validation errors. The
 * exception handler shows them in every environment, and renders the error as a normal response
 * rather than a debug page, because it describes the request rather than a fault in the code.
 */
interface PublicErrorInterface extends Throwable
{
    /** The message for the client, in place of the status's reason phrase. */
    public function publicMessage(): string;

    /**
     * More members of the JSON error body, for example ['errors' => ['title' => ['...']]].
     *
     * @return array<string, mixed>
     */
    public function details(): array;
}
