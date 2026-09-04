<?php

namespace App\Models;

use Database\Factories\CashRegisterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['unit_id', 'shift', 'float_amount', 'counted_cash', 'cash_difference', 'close_note', 'status', 'opened_by', 'closed_by', 'shortage_approved_by', 'opened_at', 'closed_at', 'last_receipt_number', 'totals_snapshot'])]
class CashRegister extends Model
{
    /** @use HasFactory<CashRegisterFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'float_amount' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'cash_difference' => 'decimal:2',
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'totals_snapshot' => 'array',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }
}
