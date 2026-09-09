<?php

namespace App\Models;

use Database\Factories\PatientAlertFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['patient_id', 'kind', 'title', 'detail', 'active', 'created_by', 'deactivated_by', 'deactivated_at', 'deactivation_reason'])]
class PatientAlert extends Model
{
    /** @use HasFactory<PatientAlertFactory> */
    use HasFactory, HasUuids;

    public const KIND_ALERGIA = 'ALERGIA';

    public const KIND_CONDICAO = 'CONDICAO';

    public const KIND_OUTRO = 'OUTRO';

    protected function casts(): array
    {
        return ['active' => 'boolean', 'deactivated_at' => 'immutable_datetime'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deactivatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by');
    }
}
