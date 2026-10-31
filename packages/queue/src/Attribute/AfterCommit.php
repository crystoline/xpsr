<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Attribute;

use Attribute;

/**
 * Queues the job only once the database transaction that is open when it is dispatched has
 * committed, so a rollback never leaves a job for work that was undone:
 *
 *     #[AfterCommit]
 *     final class IssueRefund implements JobInterface { ... }
 *
 * Dispatched inside a transaction, the job waits for the outermost transaction to commit; a
 * rollback, or a rollback to the savepoint it was dispatched in, drops it. Dispatched outside a
 * transaction, it is queued at once. On sync://, the job runs after the commit. Transactions are
 * those of the application's database connections (Crystoline\Xpsr\Database\Transactions).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AfterCommit {}
