<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth;

use Crystoline\Xpsr\Http\Exception\ForbiddenHttpException;
use Crystoline\Xpsr\Http\Exception\PublicErrorInterface;

/** 403: the API token is valid but does not allow this action (RFC 6750 insufficient_scope). */
final class InsufficientScopeException extends ForbiddenHttpException implements PublicErrorInterface
{
    public const MESSAGE = 'This token does not allow this action.';

    /** @param list<string> $scopes the scopes the action needs */
    public function __construct(private readonly array $scopes)
    {
        parent::__construct(self::MESSAGE, headers: ['WWW-Authenticate' => 'Bearer error="insufficient_scope", scope="' . implode(' ', $scopes) . '"']);
    }

    public function publicMessage(): string
    {
        return self::MESSAGE;
    }

    public function details(): array
    {
        return ['scopes' => $this->scopes];
    }
}
