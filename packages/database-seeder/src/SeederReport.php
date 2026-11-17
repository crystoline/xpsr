<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Seeder;

/** What one seeder did, table by table. */
final class SeederReport
{
    /** @var array<string, TableReport> */
    private array $tables = [];

    /** @param string|null $set the set the seeder ran in; null when it was run by its class */
    public function __construct(
        public readonly ?string $set,
        public readonly string $seeder,
    ) {}

    /** @return list<TableReport> the tables the seeder synced or filled; one it only read, as with map(), is not listed */
    public function tables(): array
    {
        return array_values(array_filter($this->tables, static fn(TableReport $table): bool => $table->touched()));
    }

    /** @internal */
    public function table(string $name): TableReport
    {
        return $this->tables[$name] ??= new TableReport($name);
    }
}
