<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Append-only: todo evento entregue pelo AppChat fica registrado (replay/auditoria). */
#[Fillable(['provider_event_id', 'type', 'phone', 'message_key', 'payload', 'result'])]
class WhatsappEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['payload' => 'array', 'received_at' => 'immutable_datetime'];
    }
}
