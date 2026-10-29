<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Eloquent\Tests\Fixtures;

use Crystoline\Xpsr\Auth\UserInterface;
use Crystoline\Xpsr\Orm\Eloquent\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

/**
 * A user model with Laravel Sanctum's tokens, as in a Laravel application.
 *
 * @property int $id
 * @property string $email
 * @property string $password
 */
final class SanctumMember extends Model implements UserInterface
{
    use Authenticatable;
    use HasApiTokens;

    public $timestamps = false;

    protected $table = 'sanctum_members';

    protected $guarded = [];
}
