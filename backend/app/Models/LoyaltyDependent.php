<?php

namespace App\Models;

use Database\Factories\LoyaltyDependentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['subscription_id', 'patient_id', 'active'])]
class LoyaltyDependent extends Model
{
    /** @use HasFactory<LoyaltyDependentFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(LoyaltySubscription::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
