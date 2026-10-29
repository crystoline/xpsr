<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\Access;

use Crystoline\Xpsr\Http\Exception\ForbiddenHttpException;
use Crystoline\Xpsr\Http\Exception\PublicErrorInterface;

/** 403: a policy refused the action (Gate::authorize()). The message is shown in every environment. */
final class AccessDeniedException extends ForbiddenHttpException implements PublicErrorInterface
{
    public const MESSAGE = 'You are not allowed to do this.';

    public function __construct(public readonly string $ability)
    {
        parent::__construct(self::MESSAGE);
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
