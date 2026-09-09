<?php

namespace App\Models;

use Database\Factories\MtSectorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['agreement_id', 'name', 'active', 'legacy_id'])]
class MtSector extends Model
{
    /** @use HasFactory<MtSectorFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    public function functions(): HasMany
    {
        return $this->hasMany(MtFunction::class);
    }

    public function asos(): HasMany
    {
        return $this->hasMany(MtAso::class);
    }
}
