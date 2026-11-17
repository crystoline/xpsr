<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Seeder;

/** What a run did: each seeder's tables, and the totals. After a dry run, what it would do. */
final class SeedReport
{
    /** @var list<SeederReport> */
    private array $seeders = [];

    public function __construct(public readonly bool $dryRun = false) {}

    /** @return list<SeederReport> in the order the seeders ran */
    public function seeders(): array
    {
        return $this->seeders;
    }

    public function inserted(): int
    {
        return $this->total(static fn(TableReport $table): int => $table->inserted);
    }

    public function updated(): int
    {
        return $this->total(static fn(TableReport $table): int => $table->updated);
    }

    public function deleted(): int
    {
        return $this->total(static fn(TableReport $table): int => $table->deleted);
    }

    public function unchanged(): int
    {
        return $this->total(static fn(TableReport $table): int => $table->unchanged);
    }

    public function changed(): bool
    {
        return $this->inserted() + $this->updated() + $this->deleted() > 0;
    }

    /** Everything the run did to one table, across its seeders. */
    public function table(string $name): TableReport
    {
        $total = new TableReport($name);
        foreach ($this->seeders as $seeder) {
            foreach ($seeder->tables() as $table) {
                if ($table->table === $name) {
                    $total->merge($table);
                }
            }
        }

        return $total;
    }

    /** @internal */
    public function seeder(?string $set, string $class): SeederReport
    {
        return $this->seeders[] = new SeederReport($set, $class);
    }

    /** @param callable(TableReport): int $count */
    private function total(callable $count): int
    {
        $total = 0;
        foreach ($this->seeders as $seeder) {
            foreach ($seeder->tables() as $table) {
                $total += $count($table);
            }
        }

        return $total;
    }
}
