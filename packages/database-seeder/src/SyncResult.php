<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Seeder;

/** What one SeedTable::sync() call did. */
final class SyncResult
{
    public function __construct(
        public readonly int $inserted,
        public readonly int $updated,
        public readonly int $unchanged,
        public readonly int $deleted,
    ) {}

    public function changed(): bool
    {
        return $this->inserted + $this->updated + $this->deleted > 0;
    }
}
