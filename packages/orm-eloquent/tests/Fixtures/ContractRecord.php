<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Eloquent\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $label
 */
final class ContractRecord extends Model
{
    public $timestamps = false;
    public $incrementing = false;
    protected $table = 'orm_contract_records';
    protected $fillable = ['id', 'label'];
}
