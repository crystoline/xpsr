<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Eloquent\Tests\Fixtures;

use Crystoline\Xpsr\Auth\UserInterface;
use Crystoline\Xpsr\Orm\Eloquent\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * A user model, and model events: "creating" lowercases the email address.
 *
 * @property int $id
 * @property string $email
 * @property string $password
 */
final class Member extends Model implements UserInterface
{
    use Authenticatable;

    public $timestamps = false;
    protected $table = 'eloquent_members';
    protected $fillable = ['email', 'password'];
    protected $hidden = ['password'];

    protected static function booted(): void
    {
        static::creating(static function (self $member): void {
            $member->email = strtolower($member->email);
        });
    }
}
