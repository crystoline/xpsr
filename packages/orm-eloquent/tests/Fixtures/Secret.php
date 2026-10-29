<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Eloquent\Tests\Fixtures;

use ArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A model with each kind of encrypted cast.
 *
 * @property int $id
 * @property string|null $token
 * @property array<string, mixed>|null $details
 * @property Collection<int, string>|null $tags
 * @property ArrayObject<string, mixed>|null $options
 */
final class Secret extends Model
{
    public $timestamps = false;

    protected $table = 'eloquent_secrets';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'details' => 'encrypted:array',
            'tags' => AsEncryptedCollection::class,
            'options' => AsEncryptedArrayObject::class,
        ];
    }
}
