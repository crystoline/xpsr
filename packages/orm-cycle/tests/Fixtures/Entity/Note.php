<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Cycle\Tests\Fixtures\Entity;

use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;
use Cycle\Annotated\Annotation\Relation\BelongsTo;
use Cycle\Annotated\Annotation\Table\Index;
use Cycle\ORM\Entity\Behavior\CreatedAt;
use DateTimeImmutable;

#[Entity(table: 'notes', repository: NoteRepository::class)]
#[Index(columns: ['pinned'], name: 'notes_pinned_idx')]
#[CreatedAt(field: 'createdAt', column: 'created_at')]
class Note
{
    #[Column(type: 'primary')]
    public ?int $id = null;

    #[Column(type: 'datetime', name: 'created_at')]
    public ?DateTimeImmutable $createdAt = null;

    #[Column(type: 'boolean', default: false)]
    public bool $pinned = false;

    #[Column(type: 'text', nullable: true)]
    public ?string $details = null;

    #[BelongsTo(target: Notebook::class, nullable: false)]
    public ?Notebook $notebook = null;

    public function __construct(
        #[Column(type: 'string(200)')]
        public string $body,
    ) {}
}
