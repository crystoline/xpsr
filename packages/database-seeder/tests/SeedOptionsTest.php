<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Seeder\Tests;

use Crystoline\Xpsr\Database\Seeder\SeedOptions;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/** The seeds configuration: what it accepts, and what it says about a mistake. */
final class SeedOptionsTest extends TestCase
{
    public function testSetsAreReadInBothForms(): void
    {
        $options = SeedOptions::fromArray([
            'sets' => [
                'reference' => ['\App\Seed\RolesSeeder', 'App\Seed\PlansSeeder'],
                'demo' => ['seeders' => ['App\Seed\DemoSeeder'], 'environments' => ['local', 'staging'], 'requires' => ['reference']],
                'empty' => [],
            ],
            'random_seed' => 2026,
        ]);

        self::assertSame(['reference', 'demo', 'empty'], array_keys($options->sets));
        self::assertSame('reference', $options->default, 'The first set is the default one.');
        self::assertSame($options->set('reference'), $options->defaultSet());
        self::assertSame(['App\Seed\RolesSeeder', 'App\Seed\PlansSeeder'], $options->set('reference')->seeders);
        self::assertNull($options->set('reference')->environments);
        self::assertTrue($options->set('reference')->allows('production'));
        self::assertSame(['reference'], $options->set('demo')->requires);
        self::assertTrue($options->set('demo')->allows('staging'));
        self::assertFalse($options->set('demo')->allows('production'));
        self::assertSame([], $options->set('empty')->seeders);
        self::assertSame('2026', $options->randomSeed);

        self::assertSame('demo', SeedOptions::fromArray(['default' => 'demo', 'sets' => ['reference' => [], 'demo' => []]])->default);
        $none = SeedOptions::fromArray([]);
        self::assertSame([], $none->sets);
        self::assertNull($none->default);
        self::assertSame('xpsr', $none->randomSeed);
    }

    /** @param array<array-key, mixed> $config */
    #[DataProvider('mistakes')]
    public function testAMistakeIsNamedByItsSetting(array $config, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        SeedOptions::fromArray($config);
    }

    /** @return iterable<string, array{array<array-key, mixed>, string}> */
    public static function mistakes(): iterable
    {
        yield 'unknown option' => [['set' => []], 'Unknown option seeds.set. Known options: sets, default, random_seed.'];
        yield 'sets as a list' => [['sets' => ['App\Seed\RolesSeeder']], 'seeds.sets must be an array of sets by name'];
        yield 'set name' => [['sets' => ['1st' => []]], 'seeds.sets has a set named "1st". A name starts with a letter'];
        yield 'set as a string' => [['sets' => ['reference' => 'App\Seed\RolesSeeder']], 'seeds.sets.reference must be a list of seeder classes, or an array with "seeders"'];
        yield 'unknown set option' => [['sets' => ['demo' => ['seeders' => [], 'env' => ['local']]]], 'Unknown option seeds.sets.demo.env. Known options: seeders, environments, requires.'];
        yield 'seeders' => [['sets' => ['demo' => ['seeders' => 'App\Seed\DemoSeeder']]], 'seeds.sets.demo.seeders must be a list of seeder class names.'];
        yield 'a seeder that is not a name' => [['sets' => ['demo' => [new stdClass()]]], 'seeds.sets.demo.seeders must be a list of seeder class names.'];
        yield 'environments' => [['sets' => ['demo' => ['seeders' => [], 'environments' => 'local']]], 'seeds.sets.demo.environments must be a list of APP_ENV values'];
        yield 'no environments' => [['sets' => ['demo' => ['seeders' => [], 'environments' => []]]], 'leave it out for every environment.'];
        yield 'requires' => [['sets' => ['demo' => ['seeders' => [], 'requires' => 'reference']]], 'seeds.sets.demo.requires must be a list of set names.'];
        yield 'requires an unknown set' => [['sets' => ['demo' => ['seeders' => [], 'requires' => ['reference']]]], 'seeds.sets.demo.requires names "reference", which is not another set.'];
        yield 'requires itself' => [['sets' => ['demo' => ['seeders' => [], 'requires' => ['demo']]]], 'seeds.sets.demo.requires names "demo", which is not another set.'];
        yield 'default' => [['sets' => ['demo' => []], 'default' => 'reference'], 'seeds.default must name a set of seeds.sets, not "reference".'];
        yield 'random seed' => [['random_seed' => ['a']], 'seeds.random_seed must be a string or an integer.'];
    }

    public function testAnUnknownSetListsTheOnesThereAre(): void
    {
        try {
            SeedOptions::fromArray([])->set('demo');
            self::fail('A set was found.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('There is no seed set "demo": config/seeds.php defines no sets.', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('There is no seed set "demo". The sets in config/seeds.php: reference, sample.');
        SeedOptions::fromArray(['sets' => ['reference' => [], 'sample' => []]])->set('demo');
    }
}
