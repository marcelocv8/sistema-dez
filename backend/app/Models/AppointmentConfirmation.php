<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['appointment_id', 'status', 'requested_on', 'requested_at', 'responded_at', 'outbox_id'])]
class AppointmentConfirmation extends Model
{
    use HasUuids;

    public const PENDING = 'PENDING';

    public const CONFIRMED = 'CONFIRMED';

    public const DECLINED = 'DECLINED';

    public const NO_RESPONSE = 'NO_RESPONSE';

    protected function casts(): array
    {
        return ['requested_on' => 'immutable_date', 'requested_at' => 'immutable_datetime', 'responded_at' => 'immutable_datetime'];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
