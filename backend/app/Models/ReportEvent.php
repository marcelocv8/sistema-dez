<?php

namespace App\Models;

use Database\Factories\ReportEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['report_id', 'event', 'by_user_id', 'payload'])]
class ReportEvent extends Model
{
    /** @use HasFactory<ReportEventFactory> */
    use HasFactory, HasUuids;

    public const CREATED = 'CRIADO';

    public const SAVED = 'SALVO';

    public const FINALIZED = 'FINALIZADO';

    public const PRINTED = 'IMPRESSO';

    public const REPRINTED = 'REIMPRESSO';

    public const SIGNED_MANUAL = 'ASSINATURA_MANUAL';

    public const APPENDED = 'ADENDO';

    public const PUBLISHED = 'PUBLICADO';

    public const UNPUBLISHED = 'REVOGADO_PORTAL';

    public const PURGED = 'RETENCAO_LIMPA';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'immutable_datetime'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function byUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'by_user_id');
    }
}
