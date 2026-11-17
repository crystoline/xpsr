<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Seeder;

use Doctrine\DBAL\Connection;
use Faker\Factory as FakerFactory;
use Faker\Generator as FakerGenerator;
use InvalidArgumentException;
use LogicException;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * What a seeder works with: the tables to fill, the connection, and sample values that are the
 * same on every machine and every run.
 */
final class Seed
{
    private ?Randomizer $random = null;

    /** @var array<string, FakerGenerator> by locale */
    private array $fakers = [];

    /** @var array<string, SeedTable> */
    private array $tables = [];

    /** @internal The runner gives each seeder its own. */
    public function __construct(
        private readonly Run $run,
        private readonly SeederReport $report,
        private readonly string $randomSeed,
        private readonly string $environment,
        private readonly string $fakerLocale,
    ) {}

    /** A table of the default connection, or of the one named. */
    public function table(string $name, ?string $connection = null): SeedTable
    {
        $label = ($connection === null ? '' : $connection . ':') . $name;

        return $this->tables[$label] ??= new SeedTable($this->run->connection($connection), $name, $this->report->table($label));
    }

    /**
     * The default connection, or the one named, inside the run's transaction: what is written
     * through it is committed with the rest of the run, and rolled back by a dry run.
     */
    public function connection(?string $name = null): Connection
    {
        return $this->run->connection($name);
    }

    /**
     * A random generator that gives this seeder the same values on every machine and every run,
     * so that sample data converges like any other: getInt(), pickArrayKeys(), shuffleArray(),
     * and the rest of PHP's Randomizer.
     */
    public function random(): Randomizer
    {
        return $this->random ??= new Randomizer(new Xoshiro256StarStar(hash('sha256', $this->randomSeed, true)));
    }

    /**
     * Faker, seeded for this seeder like random(), in the application's app.faker_locale or the
     * locale given. Needs fakerphp/faker.
     */
    public function faker(?string $locale = null): FakerGenerator
    {
        if (!class_exists(FakerFactory::class)) {
            throw new LogicException('Seed::faker() needs fakerphp/faker: run composer require fakerphp/faker, with --dev when only development sets use it. Seed::random() needs no package.');
        }
        $locale ??= $this->fakerLocale;
        if (!isset($this->fakers[$locale])) {
            $faker = FakerFactory::create($locale);
            $faker->seed(crc32($this->randomSeed . '|' . $locale));
            $this->run->seededPhpRandom = true;
            $this->fakers[$locale] = $faker;
        }

        return $this->fakers[$locale];
    }

    /**
     * $count rows, each from $row, which gets the row's number from 1.
     *
     * @template T
     * @param callable(int): T $row
     * @return list<T>
     */
    public function times(int $count, callable $row): array
    {
        if ($count < 0) {
            throw new InvalidArgumentException('Seed::times() needs a count of 0 or more.');
        }
        $rows = [];
        for ($number = 1; $number <= $count; ++$number) {
            $rows[] = $row($number);
        }

        return $rows;
    }

    /** APP_ENV, for rows that differ by environment. */
    public function environment(): string
    {
        return $this->environment;
    }

    /** Whether the run is a dry run: skip what a rollback cannot undo, such as writing files. */
    public function isDryRun(): bool
    {
        return $this->run->dryRun;
    }
}
