<?php

namespace App\Models;

use App\Enums\ProcedureType;
use Database\Factories\ProcedureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['legacy_id', 'name', 'type', 'group_name', 'tuss_code', 'duration_minutes', 'active', 'legacy', 'online_enabled', 'classification_status', 'search_name'])]
class Procedure extends Model
{
    /** @use HasFactory<ProcedureFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return ['type' => ProcedureType::class, 'active' => 'boolean', 'legacy' => 'boolean', 'online_enabled' => 'boolean'];
    }

    public function serviceOfferings(): HasMany
    {
        return $this->hasMany(ServiceOffering::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProcedurePrice::class);
    }

    public function catalogLinks(): HasMany
    {
        return $this->hasMany(CatalogLink::class);
    }
}
