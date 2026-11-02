<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Api\Tests\Fixtures\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $number
 */
final class Invoice extends Model
{
    public $timestamps = false;
    protected $table = 'api_invoices';
    protected $guarded = [];

    /** @return HasMany<Line, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(Line::class, 'invoice_id');
    }
}
