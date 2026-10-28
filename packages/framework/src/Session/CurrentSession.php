<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Session;

/**
 * The session of the request being handled, for code that has no request object, such as the
 * csrf_token() view function. SessionMiddleware sets it and clears it when the request is done.
 * Code that has the request should use Session::of($request).
 */
final class CurrentSession
{
    private ?Session $session = null;

    public function get(): ?Session
    {
        return $this->session;
    }

    /** @internal Set by SessionMiddleware. */
    public function set(?Session $session): void
    {
        $this->session = $session;
    }
}
