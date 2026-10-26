<?php

declare(strict_types=1);

namespace Crystoline\Routing;

use InvalidArgumentException;
use LogicException;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RouteDefinition
{
    private ?string $routeName = null;
    private array $constraints = [];
    private array $defaults = [];
    private array $middleware = [];
    private bool $frozen = false;

    /** @param array<array-key, mixed> $methods Validated at the registration boundary. */
    public function __construct(
        public readonly array $methods,
        public readonly string $path,
        public readonly mixed $handler,
        private readonly string $namePrefix = '',
    ) {
        if ($methods === []) {
            throw new InvalidArgumentException('A route needs at least one HTTP method.');
        }
        foreach ($methods as $method) {
            if (!is_string($method) || !preg_match('/\A[A-Z]+\z/', $method)) {
                throw new InvalidArgumentException('Route methods must be uppercase letters.');
            }
        }
        if (!is_string($handler) && !is_callable($handler) && !$handler instanceof RequestHandlerInterface
            && !(is_array($handler) && count($handler) === 2 && isset($handler[0], $handler[1])
                && (is_string($handler[0]) || is_object($handler[0])) && is_string($handler[1]))) {
            throw new InvalidArgumentException('A route handler must be a service ID, callable, controller pair, or PSR-15 handler.');
        }
        new RoutePattern($path);
    }

    public function name(string $name): self
    {
        $this->assertMutable();
        if ($name === '') {
            throw new InvalidArgumentException('Route names cannot be empty.');
        }
        $this->routeName = $this->namePrefix . $name;
        return $this;
    }

    public function where(string $parameter, string $pattern): self
    {
        $this->assertMutable();
        $constraints = [...$this->constraints, $parameter => $pattern];
        new RoutePattern($this->path, $constraints);
        $this->constraints = $constraints;
        return $this;
    }

    public function defaults(array $values): self
    {
        $this->assertMutable();
        $pattern = $this->pattern();
        foreach ($values as $key => $value) {
            $pattern->encode($key, $value);
        }
        $this->defaults = [...$this->defaults, ...$values];
        return $this;
    }

    public function middleware(string|MiddlewareInterface ...$middleware): self
    {
        $this->assertMutable();
        array_push($this->middleware, ...$middleware);
        return $this;
    }

    public function getName(): ?string
    {
        return $this->routeName;
    }
    public function getDefaults(): array
    {
        return $this->defaults;
    }
    /** @return list<string|MiddlewareInterface> */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }
    public function pattern(): RoutePattern
    {
        return new RoutePattern($this->path, $this->constraints);
    }

    /** @internal Collections freeze definitions before adapters or URL generators consume them. */
    public function freeze(): void
    {
        $this->frozen = true;
    }

    private function assertMutable(): void
    {
        if ($this->frozen) {
            throw new LogicException('Routes cannot be changed after the collection is frozen.');
        }
    }
}
