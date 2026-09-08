<?php

namespace App\Models;

use App\Services\ClinicalHtml;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['appointment_id', 'patient_id', 'professional_id', 'kind', 'content_html', 'created_by', 'updated_by', 'retention_until'])]
class ClinicalNote extends Model
{
    use HasUuids;

    public const KIND_EVOLUCAO = 'EVOLUCAO';

    public const KIND_ANAMNESE = 'ANAMNESE';

    public const KIND_INTERCORRENCIA = 'INTERCORRENCIA';

    public const KIND_ALTA = 'ALTA';

    protected function contentHtml(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => ClinicalHtml::clean($value),
            set: fn ($value) => ClinicalHtml::clean($value),
        );
    }

    protected function casts(): array
    {
        return ['retention_until' => 'immutable_date:Y-m-d'];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ProntuarioEvent::class, 'subject_id');
    }
}
