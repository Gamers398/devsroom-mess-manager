<?php

namespace App\Models;

use App\Models\Concerns\BelongsToActiveMess;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable([
    'mess_id', 'member_id', 'date', 'breakfast', 'lunch', 'dinner',
    'guest_breakfast', 'guest_lunch', 'guest_dinner', 'entered_by',
])]
class MealEntry extends Model implements AuditableContract
{
    use Auditable, BelongsToActiveMess, HasFactory;

    protected $dateFormat = 'Y-m-d';

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'breakfast' => 'integer',
            'lunch' => 'integer',
            'dinner' => 'integer',
        ];
    }

    public function mess(): BelongsTo
    {
        return $this->belongsTo(Mess::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}
