<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Api\Tests\Fixtures\CycleEntity;

use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;

/** The invoices table as a Cycle entity, beside the Doctrine entity and the Eloquent model on the same rows. */
#[Entity(table: 'api_invoices')]
class Invoice
{
    #[Column(type: 'primary')]
    public ?int $id = null;

    public function __construct(
        #[Column(type: 'string(20)')]
        public string $number,
    ) {}
}
