<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit\Tests\Fixtures\CycleEntity;

use Crystoline\Xpsr\Audit\Attribute\Audited;
use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;
use DateTimeImmutable;
use DateTimeZone;

#[Entity(table: 'audit_receipts')]
#[Audited(except: ['notes'], redact: ['iban'])]
class Receipt
{
    #[Column(type: 'primary')]
    public ?int $id = null;

    #[Column(type: 'string(255)', nullable: true)]
    public ?string $iban = null;

    #[Column(type: 'string(255)', nullable: true)]
    public ?string $notes = null;

    #[Column(type: 'datetime', name: 'issued_at')]
    public DateTimeImmutable $issuedAt;

    public function __construct(
        #[Column(type: 'string(20)')]
        public string $number,
        #[Column(type: 'integer')]
        public int $amount,
    ) {
        $this->issuedAt = new DateTimeImmutable('2026-11-01 09:00:00', new DateTimeZone('UTC'));
    }
}
