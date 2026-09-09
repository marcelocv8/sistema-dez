<?php

namespace App\Models;

use Database\Factories\MtRiskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'active', 'legacy_id'])]
class MtRisk extends Model
{
    /** @use HasFactory<MtRiskFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function asos(): HasMany
    {
        return $this->hasMany(MtAso::class);
    }
}
