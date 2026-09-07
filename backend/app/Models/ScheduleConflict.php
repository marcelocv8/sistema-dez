<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['service_offering_id', 'schedule_window_id', 'schedule_exception_id', 'reason', 'affected', 'status', 'created_by', 'resolved_by', 'resolved_at', 'resolution_note'])]
class ScheduleConflict extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['affected' => 'array', 'resolved_at' => 'immutable_datetime'];
    }

    public function serviceOffering(): BelongsTo
    {
        return $this->belongsTo(ServiceOffering::class);
    }
}
