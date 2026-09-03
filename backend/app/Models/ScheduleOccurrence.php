<?php

namespace App\Models;

use Database\Factories\ScheduleOccurrenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['service_offering_id', 'schedule_window_id', 'date', 'starts_at', 'ends_at', 'capacity', 'status'])]
class ScheduleOccurrence extends Model
{
    /** @use HasFactory<ScheduleOccurrenceFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function serviceOffering(): BelongsTo
    {
        return $this->belongsTo(ServiceOffering::class);
    }

    public function scheduleWindow(): BelongsTo
    {
        return $this->belongsTo(ScheduleWindow::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }
}
