<?php

namespace App\Models;

use Database\Factories\LoyaltySubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['plan_id', 'patient_id', 'status', 'started_at', 'ended_at', 'notes', 'created_by'])]
class LoyaltySubscription extends Model
{
    /** @use HasFactory<LoyaltySubscriptionFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return ['started_at' => 'immutable_date', 'ended_at' => 'immutable_date'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(LoyaltyPlan::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function dependents(): HasMany
    {
        return $this->hasMany(LoyaltyDependent::class, 'subscription_id');
    }

    public function dues(): HasMany
    {
        return $this->hasMany(LoyaltyDue::class, 'subscription_id');
    }
}
