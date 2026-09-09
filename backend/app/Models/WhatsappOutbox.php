<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['appointment_id', 'event_key', 'to_phone', 'body', 'status', 'provider_message_key', 'idempotency_key', 'error', 'sent_at'])]
class WhatsappOutbox extends Model
{
    use HasUuids;

    protected $table = 'whatsapp_outbox';

    public const BOOKING = 'booking';

    public const D2_CONFIRMATION = 'd2_confirmation';

    protected function casts(): array
    {
        return ['sent_at' => 'immutable_datetime'];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
