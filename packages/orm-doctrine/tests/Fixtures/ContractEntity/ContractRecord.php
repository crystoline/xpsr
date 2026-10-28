<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Doctrine\Tests\Fixtures\ContractEntity;

use Doctrine\ORM\Mapping as ORM;

/** The record OrmAdapterContractTestCase works with. */
#[ORM\Entity]
#[ORM\Table(name: 'orm_contract_records')]
class ContractRecord
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public int $id,
        #[ORM\Column(length: 100)]
        public string $label,
    ) {}
}
