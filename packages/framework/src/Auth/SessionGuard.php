<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth;

use Crystoline\Xpsr\Session\Session;
use Crystoline\Xpsr\Session\SessionMiddleware;
use LogicException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Recognizes users signed in to the browser's session (auth.guards driver "session"). Needs
 * SessionMiddleware before Authenticate.
 *
 * Each session guard keeps its sign-in under its own key, so one browser can be signed in on
 * several (a user on "web" and an admin on "admin") independently. The session keeps the user's
 * ID and a fingerprint of their password hash, so a password change signs the user out of their
 * other sessions.
 */
final class SessionGuard implements GuardInterface
{
    public const PREFIX = '_auth.';

    public function __construct(private readonly string $name, private readonly UserProviderInterface $users) {}

    /** The session key a guard signs in under. */
    public static function key(string $guard): string
    {
        return self::PREFIX . $guard;
    }

    public function authenticate(ServerRequestInterface $request): ?Identity
    {
        $session = self::session($request);
        $stored = $session->get(self::key($this->name));
        if (!is_array($stored) || !isset($stored['id']) || (!is_string($stored['id']) && !is_int($stored['id']))) {
            return null;
        }
        $user = $this->users->findById($stored['id']);
        if ($user === null || !hash_equals(self::fingerprint($user), (string) ($stored['password'] ?? ''))) {
            $session->remove(self::key($this->name));

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
        $session->set(self::key($this->name), ['id' => $user->authIdentifier(), 'password' => self::fingerprint($user)]);

        return new Identity($user, $this->name);
    }

    /**
     * Sign this guard out. When no other guard is signed in to the session, every value is removed
     * and the session gets a new ID; otherwise only the ID changes.
     */
    public function logout(ServerRequestInterface $request): void
    {
        $session = self::session($request);
        $session->remove(self::key($this->name));
        foreach (array_keys($session->all()) as $key) {
            if (str_starts_with((string) $key, self::PREFIX)) {
                $session->regenerate();

                return;
            }
        }
        $session->invalidate();
    }

    public function name(): string
    {
        return $this->name;
    }

    private static function session(ServerRequestInterface $request): Session
    {
        $session = $request->getAttribute(Session::class);
        if (!$session instanceof Session) {
            throw new LogicException('The session guard needs a session. Put ' . SessionMiddleware::class . ' before the authentication middleware, as in routes/web.php.');
        }

        return $session;
    }

    private static function fingerprint(UserInterface $user): string
    {
        return substr(hash('sha256', 'xpsr-session:' . ($user->authPasswordHash() ?? '')), 0, 32);
    }
}
