<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth;

/**
 * The identity of the request being handled, for code that has no request object, such as the
 * auth_user() and can() view functions. Authenticate sets it and clears it when the request is
 * done; login and logout update it. Code that has the request should use Identity::find($request).
 */
final class CurrentIdentity
{
    private ?Identity $identity = null;

    public function get(): ?Identity
    {
        return $this->identity;
    }

    /** @internal Set by Authenticate and AuthManager. */
    public function set(?Identity $identity): void
    {
        $this->identity = $identity;
    }
}
