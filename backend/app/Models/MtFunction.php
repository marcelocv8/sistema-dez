<?php

namespace App\Models;

use Database\Factories\MtFunctionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['agreement_id', 'sector_id', 'risk_id', 'name', 'active', 'legacy_id'])]
class MtFunction extends Model
{
    /** @use HasFactory<MtFunctionFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(MtSector::class);
    }

    public function risk(): BelongsTo
    {
        return $this->belongsTo(MtRisk::class);
    }

    public function asos(): HasMany
    {
        return $this->hasMany(MtAso::class);
    }
}
