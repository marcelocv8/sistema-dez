<?php

namespace App\Models;

use Database\Factories\QueueEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['appointment_id', 'unit_id', 'service_offering_id', 'service_date', 'checked_in_at', 'original_sequence', 'priority_code', 'priority_rank', 'priority_reason', 'reordered_by', 'called_at', 'called_by', 'status'])]
class QueueEntry extends Model
{
    /** @use HasFactory<QueueEntryFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'service_date' => 'immutable_date',
            'checked_in_at' => 'immutable_datetime',
            'called_at' => 'immutable_datetime',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function serviceOffering(): BelongsTo
    {
        return $this->belongsTo(ServiceOffering::class);
    }
}
