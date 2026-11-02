<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Api\Tests\Fixtures\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'api_invoices')]
class Invoice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    /** @var Collection<int, Line> */
    #[ORM\OneToMany(targetEntity: Line::class, mappedBy: 'invoice', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    public Collection $lines;

    public function __construct(
        #[ORM\Column(length: 20)]
        public string $number,
    ) {
        $this->lines = new ArrayCollection();
    }

    public function addLine(int $amount): Line
    {
        $line = new Line($this, $amount);
        $this->lines->add($line);

        return $line;
    }
}
