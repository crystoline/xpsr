<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Doctrine\Tests\Fixtures\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'notes')]
class Note
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column]
    public DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Column(length: 200)]
        public string $body,
    ) {
        $this->createdAt = new DateTimeImmutable('2026-01-01 00:00:00');
    }
}
