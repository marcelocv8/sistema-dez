<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['professional_id', 'period_from', 'period_to', 'status', 'subtotal_gross', 'total_commission', 'confirmed_by', 'confirmed_at', 'contest_note', 'paid_by', 'paid_at', 'payment_method_id', 'cash_movement_id', 'proof_reference'])]
class Payout extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'period_from' => 'immutable_date',
            'period_to' => 'immutable_date',
            'subtotal_gross' => 'decimal:2',
            'total_commission' => 'decimal:2',
            'confirmed_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
        ];
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayoutItem::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function cashMovement(): BelongsTo
    {
        return $this->belongsTo(CashMovement::class);
    }
}
