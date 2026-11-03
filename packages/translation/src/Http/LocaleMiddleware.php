<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Translation\Http;

use Crystoline\Xpsr\Session\Session;
use Crystoline\Xpsr\Translation\CurrentLocale;
use Crystoline\Xpsr\Translation\TranslationOptions;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Sets the request's locale from the first of translation.resolvers that has one the
 * application serves (translation.locales): the route parameter, the session value, the cookie,
 * or the Accept-Language header. The locale is kept in CurrentLocale and as the "locale" request
 * attribute while the request is handled, and app.locale comes back after.
 *
 * In app.middleware it sees the session, cookie, and header; as route middleware, after the
 * route matched, it sees the route parameter too: $routes->get('/{locale}/products', ...)
 * ->middleware(LocaleMiddleware::class).
 */
final class LocaleMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE = 'locale';

    public function __construct(private readonly CurrentLocale $current, private readonly TranslationOptions $options) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $locale = $this->resolve($request) ?? $this->current->default();
        $this->current->set($locale);
        try {
            return $handler->handle($request->withAttribute(self::ATTRIBUTE, $locale));
        } finally {
            $this->current->reset();
        }
    }

    /** The locale of a request, by the resolvers, or null when none names one the application serves. */
    public function resolve(ServerRequestInterface $request): ?string
    {
        foreach ($this->options->resolvers as $resolver) {
            $wanted = match ($resolver) {
                'route' => $request->getAttribute($this->options->routeParameter),
                'session' => $this->fromSession($request),
                'cookie' => $request->getCookieParams()[$this->options->cookie] ?? null,
                'header' => $this->fromHeader($request->getHeaderLine('Accept-Language')),
                default => null,
            };
            if (is_string($wanted) && $wanted !== '') {
                $locale = $this->options->match($wanted);
                if ($locale !== null) {
                    return $locale;
                }
            }
        }

        return null;
    }

    private function fromSession(ServerRequestInterface $request): mixed
    {
        if (!class_exists(Session::class)) {
            return null;
        }
        $session = $request->getAttribute(Session::class);

        return $session instanceof Session ? $session->get($this->options->sessionKey) : null;
    }

    /** The first language of the header, by quality, that the application serves. */
    private function fromHeader(string $header): ?string
    {
        $candidates = [];
        foreach (explode(',', $header) as $position => $part) {
            $pieces = array_map('trim', explode(';', $part));
            $tag = (string) array_shift($pieces);
            if ($tag === '' || $tag === '*') {
                continue;
            }
            $quality = 1.0;
            foreach ($pieces as $piece) {
                if (preg_match('/\Aq=([0-9.]+)\z/i', $piece, $match) === 1) {
                    $quality = (float) $match[1];
                }
            }
            if ($quality > 0) {
                $candidates[] = [$tag, $quality, $position];
            }
        }
        usort($candidates, static fn(array $a, array $b): int => $b[1] <=> $a[1] ?: $a[2] <=> $b[2]);
        foreach ($candidates as [$tag]) {
            if ($this->options->match($tag) !== null) {
                return $tag;
            }
        }

        return null;
    }
}
