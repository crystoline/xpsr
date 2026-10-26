<?php

declare(strict_types=1);

namespace Crystoline\Tests\Unit;

use Crystoline\Console\CommandRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommandRegistryTest extends TestCase
{
    public static function invalidNames(): iterable
    {
        foreach (['', 'list', 'help', 'completion', '_complete', 'Bad', 'app::test', 'app:', 'two words', '--help'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('invalidNames')]
    public function testRejectsInvalidAndReservedNames(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CommandRegistry())->register($name, 'command');
    }

    public function testRejectsDuplicateNamesAndAliasCollisionsAtomically(): void
    {
        $commands = new CommandRegistry();
        $commands->register('app:first', 'first', aliases: ['first']);
        try {
            $commands->register('app:second', 'second', aliases: ['first']);
            self::fail('Expected alias collision.');
        } catch (InvalidArgumentException) {
            self::assertCount(1, $commands->all());
        }
        $commands->register('app:second', 'second');
        $this->expectException(InvalidArgumentException::class);
        $commands->register('first', 'third');
    }

    public function testRejectsDuplicateAliasesWithinARegistration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CommandRegistry())->register('app:test', 'command', aliases: ['app:test']);
    }

    public function testRejectsEmptyServiceId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CommandRegistry())->register('app:test', '');
    }

    public function testRejectsNonStringAlias(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CommandRegistry())->register('app:test', 'command', aliases: [42]);
    }
}
