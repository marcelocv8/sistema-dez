<?php

namespace App\Models;

use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['legacy_id', 'name', 'cpf_hash', 'cpf_last_four', 'birth_date', 'phone', 'email', 'default_agreement_id', 'privacy_consent_at', 'privacy_consent_source'])]
class Patient extends Model
{
    /** @use HasFactory<PatientFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return ['birth_date' => 'date', 'privacy_consent_at' => 'immutable_datetime'];
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(PatientAlert::class);
    }

    public function clinicalNotes(): HasMany
    {
        return $this->hasMany(ClinicalNote::class);
    }
}
