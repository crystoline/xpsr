<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Api\Tests\Fixtures\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $invoice_id
 * @property int $amount
 */
final class Line extends Model
{
    public $timestamps = false;
    protected $table = 'api_lines';
    protected $guarded = [];

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }
}
