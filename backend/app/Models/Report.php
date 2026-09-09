<?php

namespace App\Models;

use App\Services\ClinicalHtml;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['appointment_id', 'patient_id', 'procedure_id', 'doctor_id', 'template_id', 'content_html', 'content_backup', 'status', 'typed_by', 'finalized_at', 'retention_until', 'adendo', 'signed_manual_by', 'signed_manual_at', 'print_count', 'last_print_by', 'last_printed_at', 'portal_access_id', 'portal_published_at', 'portal_published_by', 'portal_unpublished_at'])]
class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory, HasUuids;

    public const STATUS_RASCUNHO = 'RASCUNHO';

    public const STATUS_FINALIZADO = 'FINALIZADO';

    protected function contentHtml(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => ClinicalHtml::clean($value),
            set: fn ($value) => ClinicalHtml::clean($value),
        );
    }

    protected function casts(): array
    {
        return [
            'status' => 'string',
            'purged_at' => 'immutable_datetime',
            'finalized_at' => 'immutable_datetime',
            'retention_until' => 'immutable_date:Y-m-d',
            'signed_manual_at' => 'immutable_datetime',
            'print_count' => 'integer',
            'last_printed_at' => 'immutable_datetime',
            'portal_published_at' => 'immutable_datetime',
            'portal_unpublished_at' => 'immutable_datetime',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'doctor_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ReportTemplate::class, 'template_id');
    }

    public function typedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'typed_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ReportEvent::class);
    }

    public function portalAccess(): BelongsTo
    {
        return $this->belongsTo(PortalAccess::class);
    }
}
