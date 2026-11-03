<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Cycle\Tests\Fixtures\Entity;

use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;
use Cycle\Annotated\Annotation\Relation\HasMany;

#[Entity(table: 'notebooks')]
class Notebook
{
    #[Column(type: 'primary')]
    public ?int $id = null;

    /** @var array<int, Note> */
    #[HasMany(target: Note::class)]
    public array $notes = [];

    public function __construct(
        #[Column(type: 'string(100)')]
        public string $title,
    ) {}

    public function add(Note $note): void
    {
        $note->notebook = $this;
        $this->notes[] = $note;
    }
}
