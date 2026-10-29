<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\Http;

use Crystoline\Xpsr\Auth\Identity;
use Crystoline\Xpsr\Auth\InsufficientScopeException;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Refuses API tokens that do not allow every listed scope (403, insufficient_scope). Sessions
 * allow every scope. Runs after Authenticate:
 *
 *     ->middleware(Authenticate::guard('api'), RequireScopes::of('tasks:write'))
 */
final class RequireScopes implements MiddlewareInterface
{
    /** @param list<string> $scopes */
    private function __construct(private readonly array $scopes) {}

    public static function of(string $scope, string ...$more): self
    {
        $scopes = [$scope, ...array_values($more)];
        foreach ($scopes as $item) {
            if (!preg_match('/\A[\x21\x23-\x5B\x5D-\x7E]+\z/', $item)) {
                throw new InvalidArgumentException("\"{$item}\" is not a scope: use printable characters without spaces, quotes, or backslashes, such as tasks:write.");
            }
        }

        return new self($scopes);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $identity = Identity::of($request);
        $missing = array_values(array_filter($this->scopes, static fn(string $scope): bool => !$identity->hasScope($scope)));
        if ($missing !== []) {
            throw new InsufficientScopeException($this->scopes);
        }

        return $handler->handle($request);
    }
}
