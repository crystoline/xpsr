<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Contracts\Container;

use Closure;

interface ContainerBuilderInterface
{
    /** Register a transient class. Use instance() for literal strings or values. */
    public function bind(string $id, ?string $class = null): void;

    /** Register a shared class. */
    public function singleton(string $id, ?string $class = null): void;

    /** @param callable(\Psr\Container\ContainerInterface): mixed $factory */
    public function factory(string $id, callable $factory, bool $shared = false): void;

    public function instance(string $id, mixed $value): void;

    public function alias(string $alias, string $target): void;

    /** @param array<array-key, mixed> $ids Validated as non-empty, unique string identifiers. */
    public function defer(array $ids, Closure $loader): void;
}
