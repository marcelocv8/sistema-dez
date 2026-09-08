<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['patient_id', 'appointment_id', 'user_id', 'model', 'prompt_summary', 'response_text', 'status', 'error_message'])]
class AiRequest extends Model
{
    use HasUuids;

    public const STATUS_OK = 'OK';

    public const STATUS_ERROR = 'ERROR';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
