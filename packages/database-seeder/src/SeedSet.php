<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Seeder;

/** A named list of seeders from seeds.sets, such as "reference" or "demo". */
final class SeedSet
{
    /**
     * @param list<string> $seeders class names, in the order they run
     * @param list<string>|null $environments the APP_ENV values the set may run in; null for all
     * @param list<string> $requires the sets that run before this one
     */
    public function __construct(
        public readonly string $name,
        public readonly array $seeders,
        public readonly ?array $environments = null,
        public readonly array $requires = [],
    ) {}

    public function allows(string $environment): bool
    {
        return $this->environments === null || in_array($environment, $this->environments, true);
    }
}
