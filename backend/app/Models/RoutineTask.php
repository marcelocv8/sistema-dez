<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['type', 'ref_type', 'ref_id', 'title', 'detail', 'assigned_to', 'due_date', 'status', 'note', 'done_by', 'done_at', 'created_by'])]
class RoutineTask extends Model
{
    use HasUuids;

    public const CONFIRMACAO = 'CONFIRMACAO';

    public const ORCAMENTO_RETORNO = 'ORCAMENTO_RETORNO';

    public const ANIVERSARIANTE = 'ANIVERSARIANTE';

    public const FATURA_VENCENDO = 'FATURA_VENCENDO';

    public const ASO_VENCENDO = 'ASO_VENCENDO';

    public const MANUAL = 'MANUAL';

    protected function casts(): array
    {
        return ['due_date' => 'immutable_date:Y-m-d', 'done_at' => 'immutable_datetime'];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }
}
