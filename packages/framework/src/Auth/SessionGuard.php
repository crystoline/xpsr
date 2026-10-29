<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth;

use Crystoline\Xpsr\Session\Session;
use LogicException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Recognizes users signed in to the browser's session (auth.guards driver "session"). Needs
 * SessionMiddleware before Authenticate.
 *
 * The session keeps the user's ID and a fingerprint of their password hash, so a password change
 * signs the user out of their other sessions.
 */
final class SessionGuard implements GuardInterface
{
    public const KEY = '_auth';

    public function __construct(private readonly string $name, private readonly UserProviderInterface $users) {}

    public function authenticate(ServerRequestInterface $request): ?Identity
    {
        $session = self::session($request);
        $stored = $session->get(self::KEY);
        if (!is_array($stored) || !isset($stored['id']) || (!is_string($stored['id']) && !is_int($stored['id']))) {
            return null;
        }
        $user = $this->users->findById($stored['id']);
        if ($user === null || !hash_equals(self::fingerprint($user), (string) ($stored['password'] ?? ''))) {
            $session->remove(self::KEY);

            return null;
        }

        return new Identity($user, $this->name);
    }

    public function challenge(ServerRequestInterface $request): ?string
    {
        return null;
    }

    /** Sign the user in to the request's session, under a new session ID. */
    public function login(ServerRequestInterface $request, UserInterface $user): Identity
    {
        $session = self::session($request);
        $session->regenerate();
        $session->set(self::KEY, ['id' => $user->authIdentifier(), 'password' => self::fingerprint($user)]);

        return new Identity($user, $this->name);
    }

    /** End the request's session: every value is removed and the session gets a new ID. */
    public function logout(ServerRequestInterface $request): void
    {
        self::session($request)->invalidate();
    }

    public function name(): string
    {
        return $this->name;
    }

    private static function session(ServerRequestInterface $request): Session
    {
        $session = $request->getAttribute(Session::class);
        if (!$session instanceof Session) {
            throw new LogicException('The session guard needs a session. Put ' . \Crystoline\Xpsr\Session\SessionMiddleware::class . ' before the authentication middleware, as in routes/web.php.');
        }

        return $session;
    }

    private static function fingerprint(UserInterface $user): string
    {
        return substr(hash('sha256', 'xpsr-session:' . ($user->authPasswordHash() ?? '')), 0, 32);
    }
}
