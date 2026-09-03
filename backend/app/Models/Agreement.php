<?php

namespace App\Models;

use Database\Factories\AgreementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['legacy_id', 'name', 'type', 'is_default', 'online_enabled', 'requires_authorization', 'active', 'classification_status', 'search_name'])]
class Agreement extends Model
{
    /** @use HasFactory<AgreementFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'online_enabled' => 'boolean', 'requires_authorization' => 'boolean', 'active' => 'boolean'];
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
