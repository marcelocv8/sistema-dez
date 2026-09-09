<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['mt_aso_id', 'event', 'by_user_id', 'payload'])]
class MtAsoEvent extends Model
{
    use HasUuids;

    public const CREATED = 'CRIADO';

    public const SCHEDULED = 'AGENDADO';

    public const EXAM_LINKED = 'VINCULADO_EXAME';

    public const ISSUED = 'EMITIDO';

    public const EXTENDED = 'PRORROGADO';

    public const CANCELLED = 'CANCELADO';

    public const UPDATED = 'EDITADO';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'immutable_datetime'];
    }

    public function aso(): BelongsTo
    {
        return $this->belongsTo(MtAso::class, 'mt_aso_id');
    }

    public function byUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'by_user_id');
    }
}
