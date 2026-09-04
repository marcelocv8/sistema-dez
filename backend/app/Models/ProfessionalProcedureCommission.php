<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['professional_id', 'procedure_id', 'commission_type', 'commission_value'])]
class ProfessionalProcedureCommission extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['commission_value' => 'decimal:2'];
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }
}
