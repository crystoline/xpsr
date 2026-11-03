<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Broadcast;

use Closure;
use Crystoline\Xpsr\Auth\CurrentIdentity;
use Crystoline\Xpsr\Auth\UserInterface;
use Crystoline\Xpsr\Broadcast\Http\SubscriptionsMiddleware;
use Crystoline\Xpsr\Http\Cookie;
use InvalidArgumentException;
use LogicException;
use Psr\Http\Message\ResponseInterface;

/**
 * Who may receive private updates. broadcast.subscriptions gives each signed-in user topic
 * selectors (topics, URI templates such as "users/7/{+rest}", or "*"); a cookie carries a token
 * for them to the hub, which the browser sends with its subscription:
 *
 *     $response = $subscriptions->authorize($response, $user);   // the cookie, for the selectors
 *     $response = $subscriptions->forget($response);             // on sign-out
 *
 * hub_url() in templates and code sets the cookie for the signed-in user by itself, through
 * SubscriptionsMiddleware. A mobile application gets token() instead and sends it as a bearer.
 */
final class Subscriptions
{
    private bool $active = false;
    private ?Cookie $pending = null;

    public function __construct(
        private readonly BroadcastOptions $options,
        private readonly Tokens $tokens,
        private readonly Hub $hub,
        private readonly ?CurrentIdentity $identity = null,
    ) {}

    /**
     * The selectors a user may subscribe to; none for a guest, unless the closure of
     * broadcast.subscriptions accepts null.
     *
     * @return list<string>
     */
    public function selectors(?UserInterface $user): array
    {
        $subscriptions = $this->options->subscriptions;
        if (!$subscriptions instanceof Closure) {
            return $user === null ? [] : $subscriptions;
        }
        if ($user === null && !self::acceptsGuests($subscriptions)) {
            return [];
        }
        $selectors = $subscriptions($user);
        if (!is_array($selectors) || !array_is_list($selectors)) {
            throw new InvalidArgumentException('broadcast.subscriptions must return a list of topic selectors.');
        }
        foreach ($selectors as $selector) {
            if (!is_string($selector) || $selector === '') {
                throw new InvalidArgumentException('broadcast.subscriptions must return a list of topic selectors: non-empty strings.');
            }
        }

        return $selectors;
    }

    /** The subscriber token for a user, or null when they may subscribe to nothing private. */
    public function token(?UserInterface $user): ?string
    {
        $selectors = $this->selectors($user);

        return $selectors === [] ? null : $this->tokens->subscriber($selectors);
    }

    /** The authorization cookie for a user, or null when they may subscribe to nothing private. */
    public function cookie(?UserInterface $user): ?Cookie
    {
        $token = $this->token($user);

        return $token === null ? null : $this->makeCookie($token, $this->options->ttl);
    }

    /** The response with the user's cookie; for a user with nothing to subscribe to, the cookie is removed. */
    public function authorize(ResponseInterface $response, ?UserInterface $user): ResponseInterface
    {
        return ($this->cookie($user) ?? $this->forgetCookie())->addTo($response);
    }

    /** The response with the cookie removed, for a sign-out. */
    public function forget(ResponseInterface $response): ResponseInterface
    {
        return $this->forgetCookie()->addTo($response);
    }

    /**
     * The URL browsers subscribe at for the topics. With $authorize, the cookie of the signed-in
     * user ($user, or the current identity) is added to the response by SubscriptionsMiddleware.
     *
     * @param string|array<array-key, mixed> $topics a topic, or a list of them
     */
    public function hubUrl(string|array $topics = [], bool $authorize = true, ?UserInterface $user = null): string
    {
        $url = $this->hub->url($topics);
        if (!$authorize) {
            return $url;
        }
        $cookie = $this->cookie($user ?? $this->identity?->get()?->user);
        if ($cookie !== null) {
            if (!$this->active) {
                throw new LogicException(sprintf('hub_url() sets the authorization cookie through %s: add it to app.middleware, or set the cookie with Subscriptions::authorize() on the response.', SubscriptionsMiddleware::class));
            }
            $this->pending = $cookie;
        }

        return $url;
    }

    /** @internal For SubscriptionsMiddleware: a request begins. */
    public function begin(): void
    {
        $this->active = true;
        $this->pending = null;
    }

    /** @internal For SubscriptionsMiddleware: the request is done; the cookie hub_url() asked for, if any. */
    public function finish(): ?Cookie
    {
        $cookie = $this->pending;
        $this->pending = null;
        $this->active = false;

        return $cookie;
    }

    private function forgetCookie(): Cookie
    {
        return $this->makeCookie('', 0);
    }

    /** A cookie sent to the hub's path only, Secure when the hub is https, and never to scripts. */
    private function makeCookie(string $value, ?int $maxAge): Cookie
    {
        $hub = parse_url($this->options->publicHubUrl());
        $path = is_array($hub) ? ($hub['path'] ?? '') : '';

        return new Cookie(
            $this->options->cookie,
            $value,
            maxAge: $maxAge,
            path: $path === '' ? '/' : $path,
            domain: $this->options->cookieDomain,
            secure: !is_array($hub) || strtolower($hub['scheme'] ?? 'https') === 'https',
            httpOnly: true,
            sameSite: 'Strict',
        );
    }

    private static function acceptsGuests(Closure $subscriptions): bool
    {
        $parameter = (new \ReflectionFunction($subscriptions))->getParameters()[0] ?? null;
        $type = $parameter?->getType();

        return $parameter === null || $type === null || $type->allowsNull();
    }
}
