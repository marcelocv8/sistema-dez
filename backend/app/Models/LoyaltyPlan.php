<?php

namespace App\Models;

use Database\Factories\LoyaltyPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'agreement_id', 'monthly_fee', 'due_day', 'active'])]
class LoyaltyPlan extends Model
{
    /** @use HasFactory<LoyaltyPlanFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return ['monthly_fee' => 'decimal:2', 'due_day' => 'integer', 'active' => 'boolean'];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(LoyaltySubscription::class, 'plan_id');
    }
}
