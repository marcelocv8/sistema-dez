<?php

namespace App\Models;

use Database\Factories\QueueEntryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QueueEntry extends Model
{
    /** @use HasFactory<QueueEntryFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'service_date' => 'immutable_date',
            'checked_in_at' => 'immutable_datetime',
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
