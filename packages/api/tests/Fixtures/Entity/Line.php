<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Api\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'api_lines')]
class Line
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Invoice::class, inversedBy: 'lines')]
        #[ORM\JoinColumn(nullable: false)]
        public Invoice $invoice,
        #[ORM\Column]
        public int $amount,
    ) {}
}
