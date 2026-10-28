<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Session;

use Crystoline\Xpsr\Http\Exception\ForbiddenHttpException;
use Crystoline\Xpsr\Http\Exception\PublicErrorInterface;

/**
 * 403: a form or request without this session's CSRF token, thrown by CsrfMiddleware. The
 * message is shown in every environment, because it tells the user what to do.
 */
final class InvalidCsrfTokenException extends ForbiddenHttpException implements PublicErrorInterface
{
    public const MESSAGE = 'This form has expired. Reload the page and try again.';

    public function __construct()
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
