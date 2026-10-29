<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth;

use Crystoline\Xpsr\Http\Exception\HttpException;
use Crystoline\Xpsr\Http\Exception\PublicErrorInterface;

/** 401: the request needs a signed-in user or a valid token. The message is shown in every environment. */
final class UnauthenticatedException extends HttpException implements PublicErrorInterface
{
    public const MESSAGE = 'Authentication is required.';

    /** @param string|null $challenge the WWW-Authenticate header, such as 'Bearer error="invalid_token"' */
    public function __construct(?string $challenge = null)
    {
        parent::__construct(401, self::MESSAGE, headers: $challenge === null ? [] : ['WWW-Authenticate' => $challenge]);
    }

    public function publicMessage(): string
    {
        return self::MESSAGE;
    }

    public function details(): array
    {
        return [];
    }
}
