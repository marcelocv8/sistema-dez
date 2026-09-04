<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['payout_id', 'appointment_id', 'gross_amount', 'commission_amount', 'rule'])]
class PayoutItem extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['gross_amount' => 'decimal:2', 'commission_amount' => 'decimal:2'];
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
