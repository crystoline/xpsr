<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Seeder;

use InvalidArgumentException;

/**
 * The seeds configuration (config/seeds.php):
 *
 *   sets         name => the set. A list of seeder classes, or an array with
 *                  seeders       the seeder classes, in the order they run
 *                  environments  the APP_ENV values the set may run in; left out, every one
 *                  requires      the sets that run before it
 *   default      the set that runs when none is named (default: the first set)
 *   random_seed  what Seed::random() and Seed::faker() start from; another value gives other,
 *                equally repeatable, sample data (default: "xpsr")
 */
final class SeedOptions
{
    public const RANDOM_SEED = 'xpsr';

    /** @param array<string, SeedSet> $sets */
    public function __construct(
        public readonly array $sets = [],
        public readonly ?string $default = null,
        public readonly string $randomSeed = self::RANDOM_SEED,
    ) {}

    /** @param array<array-key, mixed> $config */
    public static function fromArray(array $config): self
    {
        foreach (array_keys($config) as $key) {
            if (!in_array($key, ['sets', 'default', 'random_seed'], true)) {
                throw new InvalidArgumentException("Unknown option seeds.{$key}. Known options: sets, default, random_seed.");
            }
        }
        $sets = $config['sets'] ?? [];
        if (!is_array($sets) || ($sets !== [] && array_is_list($sets))) {
            throw new InvalidArgumentException('seeds.sets must be an array of sets by name, such as [\'reference\' => [App\Seed\RolesSeeder::class]].');
        }
        $parsed = [];
        foreach ($sets as $name => $set) {
            if (!is_string($name) || preg_match('/\A[A-Za-z][A-Za-z0-9_.-]*\z/', $name) !== 1) {
                throw new InvalidArgumentException("seeds.sets has a set named \"{$name}\". A name starts with a letter, followed by letters, digits, dots, underscores, or hyphens.");
            }
            $parsed[$name] = self::parse($name, $set);
        }
        foreach ($parsed as $set) {
            foreach ($set->requires as $required) {
                if (!isset($parsed[$required]) || $required === $set->name) {
                    throw new InvalidArgumentException("seeds.sets.{$set->name}.requires names \"{$required}\", which is not another set.");
                }
            }
        }
        $default = $config['default'] ?? null;
        if ($default !== null && (!is_string($default) || !isset($parsed[$default]))) {
            throw new InvalidArgumentException('seeds.default must name a set of seeds.sets' . (is_string($default) ? ", not \"{$default}\"." : '.'));
        }
        $random = $config['random_seed'] ?? self::RANDOM_SEED;
        if (!is_string($random) && !is_int($random)) {
            throw new InvalidArgumentException('seeds.random_seed must be a string or an integer.');
        }

        return new self($parsed, $default ?? array_key_first($parsed), (string) $random);
    }

    public function set(string $name): SeedSet
    {
        return $this->sets[$name] ?? throw new InvalidArgumentException(
            $this->sets === []
                ? "There is no seed set \"{$name}\": config/seeds.php defines no sets."
                : "There is no seed set \"{$name}\". The sets in config/seeds.php: " . implode(', ', array_keys($this->sets)) . '.',
        );
    }

    /** The set that runs when none is named. */
    public function defaultSet(): SeedSet
    {
        if ($this->default === null) {
            throw new InvalidArgumentException('config/seeds.php defines no sets. Add one, such as \'sets\' => [\'reference\' => [App\Seed\RolesSeeder::class]].');
        }

        return $this->set($this->default);
    }

    private static function parse(string $name, mixed $set): SeedSet
    {
        if (!is_array($set)) {
            throw new InvalidArgumentException("seeds.sets.{$name} must be a list of seeder classes, or an array with \"seeders\" and, if needed, \"environments\" and \"requires\".");
        }
        if ($set === [] || array_is_list($set)) {
            $set = ['seeders' => $set];
        }
        foreach (array_keys($set) as $key) {
            if (!in_array($key, ['seeders', 'environments', 'requires'], true)) {
                throw new InvalidArgumentException("Unknown option seeds.sets.{$name}.{$key}. Known options: seeders, environments, requires.");
            }
        }
        $seeders = array_map(
            static fn(string $class): string => ltrim($class, '\\'),
            self::strings($set['seeders'] ?? [], "seeds.sets.{$name}.seeders must be a list of seeder class names."),
        );
        $environments = null;
        if (($set['environments'] ?? null) !== null) {
            $message = "seeds.sets.{$name}.environments must be a list of APP_ENV values, such as ['local', 'staging']; leave it out for every environment.";
            $environments = self::strings($set['environments'], $message);
            if ($environments === []) {
                throw new InvalidArgumentException($message);
            }
        }

        return new SeedSet($name, $seeders, $environments, self::strings($set['requires'] ?? [], "seeds.sets.{$name}.requires must be a list of set names."));
    }

    /** @return list<string> */
    private static function strings(mixed $value, string $message): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException($message);
        }
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                throw new InvalidArgumentException($message);
            }
        }

        return $value;
    }
}
