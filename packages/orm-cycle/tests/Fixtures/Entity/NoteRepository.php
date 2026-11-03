<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Cycle\Tests\Fixtures\Entity;

use Crystoline\Xpsr\Configuration;
use Cycle\ORM\Select;
use Cycle\ORM\Select\Repository;

/**
 * A repository with a dependency of its own, built from the container.
 *
 * @extends Repository<Note>
 */
final class NoteRepository extends Repository
{
    public function __construct(Select $select, public readonly Configuration $configuration)
    {
        parent::__construct($select);
    }

    /** @return list<Note> */
    public function pinned(): array
    {
        return [...$this->select()->where('pinned', true)->orderBy('id')->fetchAll()];
    }
}
