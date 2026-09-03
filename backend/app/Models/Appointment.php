<?php

namespace App\Models;

use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['patient_id', 'service_offering_id', 'schedule_occurrence_id', 'scheduled_at', 'recommended_arrival_at', 'status', 'kind', 'origin', 'price_snapshot', 'idempotency_key', 'management_token_hash', 'return_override_reason', 'cancellation_reason', 'created_by'])]
class Appointment extends Model
{
    protected $hidden = ['management_token_hash', 'idempotency_key', 'management_token'];

    /** @use HasFactory<AppointmentFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return ['scheduled_at' => 'immutable_datetime', 'recommended_arrival_at' => 'immutable_datetime', 'management_expires_at' => 'immutable_datetime', 'price_snapshot' => 'decimal:2'];
    }

    public function serviceOffering(): BelongsTo
    {
        return $this->belongsTo(ServiceOffering::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function scheduleOccurrence(): BelongsTo
    {
        return $this->belongsTo(ScheduleOccurrence::class);
    }
}
