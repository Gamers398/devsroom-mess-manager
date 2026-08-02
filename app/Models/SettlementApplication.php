<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One application of a Payment against a PendingSettlement (FIFO). Sums to the
 * settlement's amount_settled. Has no mess_id (it inherits scope via its
 * settlement) so it intentionally does NOT use BelongsToActiveMess.
 */
#[Fillable(['pending_settlement_id', 'payment_id', 'applied_amount'])]
class SettlementApplication extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'applied_amount' => 'decimal:2',
        ];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(PendingSettlement::class, 'pending_settlement_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
