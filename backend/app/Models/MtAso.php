<?php

namespace App\Models;

use App\Enums\AsoStatus;
use App\Enums\AsoType;
use Database\Factories\MtAsoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['patient_id', 'agreement_id', 'sector_id', 'function_id', 'type', 'status', 'realized_at', 'expires_at', 'appointment_id', 'report_id', 'issued_at', 'notes', 'legacy_situacao', 'legacy_id', 'created_by'])]
class MtAso extends Model
{
    /** @use HasFactory<MtAsoFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'type' => AsoType::class,
            'status' => AsoStatus::class,
            'realized_at' => 'immutable_date',
            'expires_at' => 'immutable_date',
            'issued_at' => 'immutable_datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(MtSector::class);
    }

    public function function(): BelongsTo
    {
        return $this->belongsTo(MtFunction::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function risks(): BelongsToMany
    {
        return $this->belongsToMany(MtRisk::class, 'mt_aso_risks', 'mt_aso_id', 'risk_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MtAsoEvent::class, 'mt_aso_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
