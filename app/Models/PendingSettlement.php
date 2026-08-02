<?php

namespace App\Models;

use App\Models\Concerns\BelongsToActiveMess;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * One member's residual (due or credit) snapshotted from a closed month (or a
 * correction) — a tracked item that must be cleared by a real cash transaction.
 *
 * `amount_settled` is the running total cleared against `original_amount`
 * (via settlement_applications for dues, or a manual mark-settled for credits).
 * Status is DERIVED (getStatusAttribute) from the two so it can never drift.
 */
#[Fillable([
    'mess_id', 'member_id', 'source_closing_id', 'monthly_correction_id',
    'source_year', 'source_month', 'source', 'kind',
    'original_amount', 'amount_settled',
    'settled_by', 'settled_at', 'settlement_method',
])]
class PendingSettlement extends Model implements AuditableContract
{
    use Auditable, BelongsToActiveMess, HasFactory;

    protected function casts(): array
    {
        return [
            'original_amount' => 'decimal:2',
            'amount_settled' => 'decimal:2',
            'settled_at' => 'datetime',
        ];
    }

    /** Remaining unsettled amount as a canonical 2-decimal string. */
    public function amountRemaining(): string
    {
        return bcsub((string) $this->original_amount, (string) $this->amount_settled, 2);
    }

    /**
     * Derived status — never stored. Mirrors get-status calls in the UI and
     * keeps a single source of truth (amount_settled vs original_amount).
     */
    public function getStatusAttribute(): string
    {
        if (bccomp($this->amountRemaining(), '0', 2) <= 0) {
            return 'settled';
        }

        return bccomp((string) $this->amount_settled, '0', 2) > 0 ? 'partial' : 'pending';
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function sourceClosing(): BelongsTo
    {
        return $this->belongsTo(MonthlyClosing::class, 'source_closing_id');
    }

    public function sourceCorrection(): BelongsTo
    {
        return $this->belongsTo(MonthlyCorrection::class, 'monthly_correction_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(SettlementApplication::class);
    }

    public function settledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }
}
