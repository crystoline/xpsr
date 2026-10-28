<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Session;

use Crystoline\Xpsr\Http\Cookie;

/**
 * Loads sessions from the store and saves them, for SessionMiddleware and for tests.
 *
 * An ID from a cookie is used only when the store has a session under it. Otherwise the browser
 * gets a new random ID, so nobody can choose the ID of a session (session fixation).
 */
final class SessionManager
{
    public function __construct(
        private readonly SessionStoreInterface $store,
        private readonly SessionOptions $options,
    ) {}

    public function options(): SessionOptions
    {
        return $this->options;
    }

    public function store(): SessionStoreInterface
    {
        return $this->store;
    }

    /** The session with this ID, or a new one when the ID is missing, malformed, unknown, or expired. */
    public function load(?string $id): Session
    {
        if ($id !== null && Session::isValidId($id)) {
            $payload = $this->store->read($id);
            $session = $payload === null ? null : Session::fromPayload($id, $payload);
            if ($session !== null) {
                return $session;
            }
        }

        return new Session();
    }

    /**
     * Write the session, or forget it when it holds nothing. Returns the cookie the response needs:
     * the session ID, a cookie that deletes the browser's when the session ended, or null.
     *
     * @param bool $hadCookie whether the request sent a session cookie
     * @param bool $https whether the request came over HTTPS, for the Secure flag
     */
    public function save(Session $session, bool $hadCookie, bool $https): ?Cookie
    {
        $stored = $session->storedId();
        if ($stored !== null && $stored !== $session->id()) {
            $this->store->destroy($stored);
        }
        if ($session->isEmpty()) {
            if ($stored !== null && $stored === $session->id()) {
                $this->store->destroy($stored);
            }

            // Max-Age=0 deletes the browser's cookie.
            return $hadCookie ? $this->cookie('', 0, $https) : null;
        }
        $this->store->write($session->id(), $session->payload());

        return $this->cookie($session->id(), $this->options->lifetime, $https);
    }

    private function cookie(string $value, int $maxAge, bool $https): Cookie
    {
        // Browsers ignore Secure cookies sent over plain HTTP, except to localhost.
        return new Cookie(
            $this->options->cookieName,
            $value,
            maxAge: $maxAge,
            path: $this->options->cookiePath,
            domain: $this->options->cookieDomain,
            secure: $this->options->secure ?? $https,
            httpOnly: true,
            sameSite: $this->options->sameSite,
        );
    }

    /** Remove expired sessions about once every prune_every calls. */
    public function maybePrune(): void
    {
        if ($this->options->pruneEvery > 0 && random_int(1, $this->options->pruneEvery) === 1) {
            $this->store->prune();
        }
    }
}
