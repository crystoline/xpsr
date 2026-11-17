<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Seeder\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * An Eloquent model on the fixture roles table.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property int $level
 */
final class Role extends Model
{
    public $timestamps = false;
    protected $guarded = [];

    public function getTable(): string
    {
        return Tables::name('roles');
    }
}
