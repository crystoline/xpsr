<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Not #[Audited]: nothing about it is recorded. */
#[ORM\Entity]
#[ORM\Table(name: 'audit_notes')]
class Note
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 200)]
        public string $body,
    ) {}
}
