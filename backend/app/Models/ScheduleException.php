<?php

namespace App\Models;

use Database\Factories\ScheduleExceptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['service_offering_id', 'date', 'starts_at', 'ends_at', 'reason', 'blocked', 'created_by'])]
class ScheduleException extends Model
{
    /** @use HasFactory<ScheduleExceptionFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'blocked' => 'boolean'];
    }

    public function serviceOffering(): BelongsTo
    {
        return $this->belongsTo(ServiceOffering::class);
    }
}
