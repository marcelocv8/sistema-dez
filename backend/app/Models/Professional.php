<?php

namespace App\Models;

use Database\Factories\ProfessionalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['legacy_id', 'name', 'council_acronym', 'council_number', 'commission_type', 'commission_value', 'user_id', 'active', 'online_enabled', 'classification_status', 'search_name'])]
class Professional extends Model
{
    /** @use HasFactory<ProfessionalFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return ['active' => 'boolean', 'online_enabled' => 'boolean', 'commission_value' => 'decimal:2'];
    }

    public function serviceOfferings(): HasMany
    {
        return $this->hasMany(ServiceOffering::class);
    }

    public function catalogLinks(): HasMany
    {
        return $this->hasMany(CatalogLink::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(ProfessionalProcedureCommission::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
