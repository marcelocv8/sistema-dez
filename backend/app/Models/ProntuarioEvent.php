<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['patient_id', 'appointment_id', 'subject_id', 'event', 'by_user_id', 'payload'])]
class ProntuarioEvent extends Model
{
    use HasUuids;

    public const VIEWED = 'VISUALIZADO';

    public const NOTE_CREATED = 'NOTA_CRIADA';

    public const NOTE_UPDATED = 'NOTA_EDITADA';

    public const ALERT_ADDED = 'ALERTA_INCLUIDO';

    public const ALERT_DEACTIVATED = 'ALERTA_INATIVADO';

    public const PUBLISHED = 'PUBLICADO_PORTAL';

    public const AI_REQUESTED = 'IA_SOLICITADA';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'immutable_datetime'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function byUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'by_user_id');
    }
}
