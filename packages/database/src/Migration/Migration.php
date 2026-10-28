<?php

declare(strict_types=1);

namespace Crystoline\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\TableEditor;

/**
 * A database migration. Each migration file returns one instance, usually of an anonymous
 * class:
 *
 *   return new class extends Migration {
 *       public function up(Connection $db): void { ... }
 *       public function down(Connection $db): void { ... }
 *   };
 *
 * On databases with transactional DDL (PostgreSQL, SQLite), up() and down() run in a
 * transaction together with the migration's bookkeeping, so a failure leaves nothing half
 * applied. MySQL and MariaDB commit each schema change immediately; there, a migration that
 * fails part-way stays partly applied and must be repaired by hand.
 */
abstract class Migration
{
    /** Set to false for statements that cannot run inside a transaction. */
    protected bool $transactional = true;

    /** Apply the change. */
    abstract public function up(Connection $db): void;

    /** Undo the change. The default refuses, so rollbacks stop at this migration. */
    public function down(Connection $db): void
    {
        throw new MigrationException('This migration cannot be rolled back: it has no down() method.');
    }

    public function isTransactional(): bool
    {
        return $this->transactional;
    }

    /**
     * Change an existing table: $change receives an editor for the table as it is now, and
     * the difference is applied. Nothing runs when $change changes nothing.
     *
     * @param callable(TableEditor): mixed $change
     */
    protected function alterTable(Connection $db, string $table, callable $change): void
    {
        $schema = $db->createSchemaManager();
        $current = $schema->introspectTableByUnquotedName($table);
        $editor = $current->edit();
        $change($editor);
        $diff = $schema->createComparator()->compareTables($current, $editor->create());
        if (!$diff->isEmpty()) {
            $schema->alterTable($diff);
        }
    }
}
