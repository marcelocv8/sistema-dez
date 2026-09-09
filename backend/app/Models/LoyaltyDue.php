<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\LoyaltyDueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['subscription_id', 'competence', 'amount', 'due_date', 'status', 'paid_at', 'cash_movement_id', 'collected_by'])]
class LoyaltyDue extends Model
{
    /** @use HasFactory<LoyaltyDueFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return ['competence' => 'immutable_date', 'due_date' => 'immutable_date', 'amount' => 'decimal:2', 'paid_at' => 'immutable_datetime'];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(LoyaltySubscription::class);
    }

    public function cashMovement(): BelongsTo
    {
        return $this->belongsTo(CashMovement::class);
    }

    public function isOverdue(CarbonImmutable $today): bool
    {
        return $this->status === 'PENDENTE' && $this->due_date->lessThan($today);
    }
}
