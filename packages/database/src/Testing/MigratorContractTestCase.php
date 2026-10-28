<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Testing;

use Crystoline\Xpsr\Database\Migration\MigrationException;
use Crystoline\Xpsr\Database\Migration\MigratorInterface;
use PHPUnit\Framework\TestCase;

/**
 * The behavior every MigratorInterface implementation must have, so that migrate,
 * migrate:rollback, and migrate:status work the same with any runner. Extend it in the tests of
 * a runner package and implement the hooks. Each test starts with an empty migration source and
 * a database without the tables it names.
 */
abstract class MigratorContractTestCase extends TestCase
{
    /** A runner over the migration source that the add*() hooks write to. */
    abstract protected function migrator(): MigratorInterface;

    /**
     * Add a migration that creates $table in up() and drops it in down(). Migrations added later
     * sort after earlier ones.
     *
     * @return string the migration's name as the runner reports it
     */
    abstract protected function addMigration(string $table): string;

    /** Add a migration whose up() fails. */
    abstract protected function addFailingMigration(): string;

    abstract protected function tableExists(string $table): bool;

    public function testAnEmptySourceHasNothingToDo(): void
    {
        $migrator = $this->migrator();
        self::assertSame([], $migrator->status());
        self::assertSame([], $migrator->pending());
        self::assertSame([], $migrator->migrate());
        self::assertSame([], $migrator->rollback());
    }

    public function testMigrateRunsPendingMigrationsInOrderOnce(): void
    {
        $first = $this->addMigration('contract_first');
        $second = $this->addMigration('contract_second');
        $migrator = $this->migrator();
        self::assertSame([$first, $second], $migrator->pending());
        self::assertSame(['pending', 'pending'], array_column($migrator->status(), 'status'));

        $progress = [];
        self::assertSame([$first, $second], $migrator->migrate(static function (string $name) use (&$progress): void {
            $progress[] = $name;
        }));
        self::assertSame([$first, $second], $progress, 'The progress callback sees each migration before it runs.');
        self::assertTrue($this->tableExists('contract_first'));
        self::assertTrue($this->tableExists('contract_second'));
        self::assertSame([], $migrator->pending());
        self::assertSame([$first, $second], array_column($migrator->status(), 'migration'));
        self::assertSame(['ran', 'ran'], array_column($migrator->status(), 'status'));
        self::assertSame([], $migrator->migrate());
    }

    public function testRollbackUndoesTheNewestMigrationsFirst(): void
    {
        $first = $this->addMigration('contract_first');
        $this->migrator()->migrate();
        $second = $this->addMigration('contract_second');
        $third = $this->addMigration('contract_third');
        $migrator = $this->migrator();
        $migrator->migrate();

        self::assertSame([$third], $migrator->rollback(steps: 1));
        self::assertFalse($this->tableExists('contract_third'));
        self::assertSame(['ran', 'ran', 'pending'], array_column($migrator->status(), 'status'));

        $progress = [];
        self::assertSame([$second, $first], $migrator->rollback(steps: 10, progress: static function (string $name) use (&$progress): void {
            $progress[] = $name;
        }));
        self::assertSame([$second, $first], $progress);
        self::assertFalse($this->tableExists('contract_first'));
        self::assertSame(['pending', 'pending', 'pending'], array_column($migrator->status(), 'status'));
        self::assertSame([], $migrator->rollback());
    }

    public function testRollbackWithoutStepsUndoesAtLeastTheNewestMigration(): void
    {
        $this->addMigration('contract_first');
        $newest = $this->addMigration('contract_second');
        $migrator = $this->migrator();
        $migrator->migrate();
        $rolledBack = $migrator->rollback();
        self::assertNotSame([], $rolledBack);
        self::assertSame($newest, $rolledBack[0], 'The newest migration is rolled back first.');
        self::assertFalse($this->tableExists('contract_second'));
    }

    public function testAFailureStopsTheRunAndKeepsEarlierMigrations(): void
    {
        $first = $this->addMigration('contract_first');
        $failing = $this->addFailingMigration();
        $this->addMigration('contract_after');
        $migrator = $this->migrator();
        try {
            $migrator->migrate();
            self::fail('The failing migration did not throw.');
        } catch (MigrationException $exception) {
            self::assertStringContainsString($failing, $exception->getMessage(), 'The message names the failed migration.');
        }
        self::assertTrue($this->tableExists('contract_first'));
        self::assertFalse($this->tableExists('contract_after'), 'Migrations after a failure do not run.');
        $status = array_column($migrator->status(), 'status', 'migration');
        self::assertSame('ran', $status[$first]);
        self::assertSame('pending', $status[$failing]);
    }

    public function testRollbackNeedsAtLeastOneStep(): void
    {
        $this->expectException(MigrationException::class);
        $this->migrator()->rollback(steps: 0);
    }
}
