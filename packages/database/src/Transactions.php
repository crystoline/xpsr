<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs code once a database transaction has committed, and drops it if the transaction rolls
 * back:
 *
 *     $db->transactional(function () use ($transactions, $queue, $order): void {
 *         $order->markPaid();
 *         $transactions->afterCommit(static fn() => $queue->dispatch(new SendReceipt($order->id)));
 *     });
 *
 * Callbacks wait for the outermost transaction: inside nested transactions (savepoints), they run
 * when the whole transaction commits, and rolling back to the savepoint they were added in drops
 * them. Without an open transaction, a callback runs at once. The shared connections of
 * ConnectionManager report their transactions here; connections from open() do not.
 */
final class Transactions
{
    /** @var array<string, array<int, list<callable>>> connection => nesting level => callbacks, for each open transaction that counts */
    private array $open = [];

    /** @var array<string, array<int, int>> connection => nesting level => the order the transaction began in */
    private array $order = [];

    private int $sequence = 0;

    /** @var array<string, int> connection => the nesting level a test holds; transactions at or below it do not count */
    private array $base = [];

    public function __construct(private readonly ?LoggerInterface $logger = null) {}

    /**
     * Runs $callback after the open transaction commits, or at once when none is open.
     *
     * @param string|null $connection the connection whose transaction to wait for; without it,
     *                                the transaction that began last, on any connection
     */
    public function afterCommit(callable $callback, ?string $connection = null): void
    {
        $current = $this->current($connection);
        if ($current === null) {
            $callback();

            return;
        }
        [$name, $level] = $current;
        $this->open[$name][$level][] = $callback;
    }

    /** Whether a transaction is open on $connection, or on any connection. */
    public function inTransaction(?string $connection = null): bool
    {
        return $this->current($connection) !== null;
    }

    /** @internal A transaction began on $connection, which is now at $level. */
    public function begun(string $connection, int $level): void
    {
        if ($level <= ($this->base[$connection] ?? 0)) {
            return;
        }
        $this->open[$connection][$level] = [];
        $this->order[$connection][$level] = ++$this->sequence;
    }

    /**
     * @internal The transaction at $level on $connection is about to commit. Returns the callbacks
     * to run once it has: none for a savepoint, whose callbacks move to the transaction around it.
     *
     * @return list<callable>
     */
    public function committing(string $connection, int $level): array
    {
        $base = $this->base[$connection] ?? 0;
        if ($level <= $base) {
            return [];
        }
        $callbacks = $this->open[$connection][$level] ?? [];
        $this->forget($connection, $level);
        if ($level > $base + 1) {
            $this->open[$connection][$level - 1] = [...($this->open[$connection][$level - 1] ?? []), ...$callbacks];

            return [];
        }

        return $callbacks;
    }

    /** @internal The transaction at $level on $connection rolled back, with any nested in it: their callbacks are dropped. */
    public function rolledBack(string $connection, int $level): void
    {
        foreach (array_keys($this->open[$connection] ?? []) as $open) {
            if ($open >= $level) {
                $this->forget($connection, $open);
            }
        }
    }

    /**
     * @internal Runs the callbacks of a committed transaction in order. One that throws does not
     * stop the others: its exception is thrown once the last one has run, and any further
     * exceptions are logged.
     *
     * @param list<callable> $callbacks
     */
    public function run(array $callbacks): void
    {
        $first = null;
        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (Throwable $exception) {
                if ($first === null) {
                    $first = $exception;
                } else {
                    $this->logger?->error('An after-commit callback failed: ' . $exception->getMessage(), ['exception' => $exception]);
                }
            }
        }
        if ($first !== null) {
            throw $first;
        }
    }

    /**
     * @internal For DatabaseTransactions: the transactions a test holds on $connection, up to
     * $level, never commit, so they do not count, and callbacks run when the code under test
     * commits its own outermost transaction. 0 makes every transaction count again.
     */
    public function ignoreUpTo(string $connection, int $level): void
    {
        $this->base[$connection] = $level;
        foreach (array_keys($this->open[$connection] ?? []) as $open) {
            if ($open <= $level) {
                $this->forget($connection, $open);
            }
        }
    }

    /** @return array{string, int}|null the connection and level of the transaction a callback would wait for */
    private function current(?string $connection): ?array
    {
        if ($connection !== null) {
            $levels = $this->open[$connection] ?? [];

            return $levels === [] ? null : [$connection, max(array_keys($levels))];
        }
        $latest = null;
        $latestSequence = 0;
        foreach ($this->order as $name => $levels) {
            foreach ($levels as $level => $sequence) {
                if ($sequence > $latestSequence) {
                    [$latest, $latestSequence] = [[$name, $level], $sequence];
                }
            }
        }

        return $latest;
    }

    private function forget(string $connection, int $level): void
    {
        unset($this->open[$connection][$level], $this->order[$connection][$level]);
        if (($this->open[$connection] ?? []) === []) {
            unset($this->open[$connection], $this->order[$connection]);
        }
    }
}
