<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Seeder;

use Crystoline\Xpsr\Database\ConnectionManager;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Runs seeders: the sets of config/seeds.php, or seeder classes by name. A run happens in one
 * transaction on each connection it touches, so it is kept whole or not at all, and a dry run
 * does everything and rolls it back.
 */
final class SeedRunner
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ConnectionManager $connections,
        private readonly SeedOptions $options,
        private readonly string $environment,
        private readonly string $fakerLocale = 'en_US',
    ) {}

    /**
     * Runs the sets named, each after the sets it requires; without a name, the default set.
     *
     * @param list<string> $sets
     * @param string|null $only one seeder of those sets to run alone, by its class name or the last part of it
     * @throws SeedException when a set is not for this environment, or a seeder fails; nothing is changed then
     */
    public function run(array $sets = [], bool $dryRun = false, ?string $only = null): SeedReport
    {
        $plan = [];
        foreach ($this->resolve($sets) as $set) {
            foreach ($set->seeders as $class) {
                $plan[$class] ??= [$set->name, $this->seederClass($class, "seeds.sets.{$set->name} lists {$class}")];
            }
        }
        if ($only !== null) {
            $wanted = ltrim($only, '\\');
            $matches = array_filter($plan, static fn(array $entry): bool => $entry[1] === $wanted || str_ends_with($entry[1], '\\' . $wanted));
            if ($matches === []) {
                throw new InvalidArgumentException("No seeder of the sets is named \"{$only}\". Their seeders: " . implode(', ', array_keys($plan)) . '.');
            }
            $plan = $matches;
        }

        return $this->execute(array_values($plan), $dryRun);
    }

    /**
     * Runs seeder classes that need not be in a set, such as a scenario for a test.
     *
     * @param list<string> $seeders class names
     */
    public function runSeeders(array $seeders, bool $dryRun = false): SeedReport
    {
        $plan = [];
        foreach ($seeders as $class) {
            $plan[] = [null, $this->seederClass(ltrim($class, '\\'), $class)];
        }

        return $this->execute($plan, $dryRun);
    }

    /**
     * The sets a run of $sets loads, in order: each set after the ones it requires.
     *
     * @param list<string> $sets names; none for the default set
     * @return list<SeedSet>
     * @throws SeedException when one of them is not for this environment
     */
    public function resolve(array $sets = []): array
    {
        $resolved = [];
        foreach ($sets === [] ? [$this->options->defaultSet()->name] : $sets as $name) {
            $this->visit($this->options->set($name), $resolved, []);
        }
        foreach ($resolved as $set) {
            if (!$set->allows($this->environment)) {
                throw new SeedException(sprintf(
                    'The "%s" set is for %s (seeds.sets.%s.environments), and APP_ENV is %s.',
                    $set->name,
                    implode(', ', $set->environments ?? []),
                    $set->name,
                    $this->environment,
                ));
            }
        }

        return array_values($resolved);
    }

    public function options(): SeedOptions
    {
        return $this->options;
    }

    /** APP_ENV, which decides the sets that may run. */
    public function environment(): string
    {
        return $this->environment;
    }

    /**
     * @param array<string, SeedSet> $resolved
     * @param list<string> $path the sets that led here, to name a circle of requirements
     */
    private function visit(SeedSet $set, array &$resolved, array $path): void
    {
        if (isset($resolved[$set->name])) {
            return;
        }
        if (in_array($set->name, $path, true)) {
            throw new InvalidArgumentException('The sets of seeds.sets require each other in a circle: ' . implode(', ', [...$path, $set->name]) . '.');
        }
        foreach ($set->requires as $required) {
            $this->visit($this->options->set($required), $resolved, [...$path, $set->name]);
        }
        $resolved[$set->name] = $set;
    }

    /** @return class-string<SeederInterface> */
    private function seederClass(string $class, string $where): string
    {
        if (!class_exists($class)) {
            throw new InvalidArgumentException("{$where}, which does not exist.");
        }
        if (!is_subclass_of($class, SeederInterface::class)) {
            throw new InvalidArgumentException("{$where}, which does not implement " . SeederInterface::class . '.');
        }

        return $class;
    }

    /** @param list<array{string|null, class-string<SeederInterface>}> $plan the set and the seeder */
    private function execute(array $plan, bool $dryRun): SeedReport
    {
        $run = new Run($this->connections, $dryRun);
        $report = new SeedReport($dryRun);
        // The default connection's transaction also covers what a seeder writes through an ORM.
        $run->connection();
        $current = '';
        try {
            foreach ($plan as [$set, $class]) {
                $current = $class;
                $seeder = $this->container->get($class);
                if (!$seeder instanceof SeederInterface) {
                    throw new InvalidArgumentException("The container gave something else than a seeder for {$class}.");
                }
                $seeder->run(new Seed($run, $report->seeder($set, $class), $this->options->randomSeed . '|' . $class, $this->environment, $this->fakerLocale));
            }
        } catch (Throwable $exception) {
            try {
                $run->finish(false);
            } catch (Throwable) {
                // A connection that is gone has rolled back by itself; the seeder's failure is the one to report.
            }

            throw new SeedException("{$current} failed, and nothing was changed: {$exception->getMessage()}", previous: $exception);
        }
        $run->finish(!$dryRun);

        return $report;
    }
}
