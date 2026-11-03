<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Cycle\Tests\Fixtures\ContractEntity;

use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;

/** The record OrmAdapterContractTestCase works with. */
#[Entity(table: 'orm_contract_records')]
class ContractRecord
{
    public function __construct(
        #[Column(type: 'integer', primary: true)]
        public int $id,
        #[Column(type: 'string(100)')]
        public string $label,
    ) {}
}
