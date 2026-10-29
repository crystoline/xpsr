<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Eloquent\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** A model on the XPSR connection named "other". */
final class OtherRecord extends Model
{
    public $timestamps = false;
    protected $connection = 'other';
    protected $table = 'other_records';
    protected $fillable = ['label'];
}
