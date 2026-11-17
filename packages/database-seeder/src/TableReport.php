<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Seeder;

/**
 * What seeding did to one table: how many rows were inserted, updated, deleted, and left as
 * they were, and which rows changed, named by their key. Values are never listed, so a report
 * can go into a deployment log.
 */
final class TableReport
{
    /** The most changed rows listed for a table; the rest are counted in $omitted. */
    public const MAX_CHANGES = 100;

    public private(set) int $inserted = 0;
    public private(set) int $updated = 0;
    public private(set) int $unchanged = 0;
    public private(set) int $deleted = 0;

    /** @var list<string> such as "+ slug=editor", "~ slug=admin (name)", and "- slug=guest" */
    public private(set) array $changes = [];
    public private(set) int $omitted = 0;

    /** @var list<string> */
    public private(set) array $notes = [];

    public function __construct(public readonly string $table) {}

    public function changed(): bool
    {
        return $this->inserted + $this->updated + $this->deleted > 0;
    }

    /** Whether the seeder synced or filled the table, changed or not, and did not only read it. */
    public function touched(): bool
    {
        return $this->inserted + $this->updated + $this->unchanged + $this->deleted > 0 || $this->notes !== [];
    }

    /** Such as "1 inserted, 2 updated, 5 unchanged". */
    public function summary(): string
    {
        $parts = [];
        foreach (['inserted' => $this->inserted, 'updated' => $this->updated, 'deleted' => $this->deleted, 'unchanged' => $this->unchanged] as $label => $count) {
            if ($count > 0) {
                $parts[] = "{$count} {$label}";
            }
        }
        $parts = [...$parts, ...$this->notes];

        return $parts === [] ? 'nothing to do' : implode(', ', $parts);
    }

    /** @internal */
    public function add(int $inserted = 0, int $updated = 0, int $unchanged = 0, int $deleted = 0): void
    {
        $this->inserted += $inserted;
        $this->updated += $updated;
        $this->unchanged += $unchanged;
        $this->deleted += $deleted;
    }

    /** @internal */
    public function change(string $line): void
    {
        if (count($this->changes) < self::MAX_CHANGES) {
            $this->changes[] = $line;
        } else {
            ++$this->omitted;
        }
    }

    /** @internal */
    public function note(string $note): void
    {
        if (!in_array($note, $this->notes, true)) {
            $this->notes[] = $note;
        }
    }

    /** @internal */
    public function merge(self $other): void
    {
        $this->add($other->inserted, $other->updated, $other->unchanged, $other->deleted);
        foreach ($other->changes as $line) {
            $this->change($line);
        }
        $this->omitted += $other->omitted;
        foreach ($other->notes as $note) {
            $this->note($note);
        }
    }
}
