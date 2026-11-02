<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit\Tests\Fixtures\Model;

use Crystoline\Xpsr\Audit\Eloquent\Audited;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $reference
 * @property int $amount
 * @property string $status
 * @property string|null $card_number
 * @property string|null $notes
 * @property string|null $password
 */
final class Payment extends Model
{
    use Audited;

    public $timestamps = false;
    protected $table = 'audit_payments';
    protected $guarded = [];

    public function auditExcept(): array
    {
        return ['notes'];
    }

    public function auditRedact(): array
    {
        return ['card_number'];
    }
}
