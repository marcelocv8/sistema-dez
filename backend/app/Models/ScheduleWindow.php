<?php

namespace App\Models;

use Database\Factories\ScheduleWindowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['service_offering_id', 'effective_from', 'effective_to', 'weekday', 'starts_at', 'ends_at', 'slot_minutes', 'daily_capacity_override', 'active'])]
class ScheduleWindow extends Model
{
    /** @use HasFactory<ScheduleWindowFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return ['effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d', 'active' => 'boolean'];
    }

    public function serviceOffering(): BelongsTo
    {
        return $this->belongsTo(ServiceOffering::class);
    }
}
