<?php

namespace App\Models;

use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['legacy_id', 'name', 'timezone', 'cnpj', 'active', 'operational_enabled'])]
class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return ['active' => 'boolean', 'operational_enabled' => 'boolean'];
    }

    public function serviceOfferings(): HasMany
    {
        return $this->hasMany(ServiceOffering::class);
    }

    public function catalogLinks(): HasMany
    {
        return $this->hasMany(CatalogLink::class);
    }
}
