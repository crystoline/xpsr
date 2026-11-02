<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit\Tests\Fixtures\Entity;

use Crystoline\Xpsr\Audit\Attribute\Audited;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'audit_invoices')]
#[Audited(except: ['notes'], redact: ['iban'])]
class Invoice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $iban = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $notes = null;

    public function __construct(
        #[ORM\Column(length: 20)]
        public string $number,
        #[ORM\Column]
        public int $amount,
        #[ORM\Column]
        public DateTimeImmutable $issuedAt = new DateTimeImmutable('2026-11-01 09:00:00', new \DateTimeZone('UTC')),
    ) {}
}
