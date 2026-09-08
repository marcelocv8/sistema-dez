<?php

namespace App\Models;

use Database\Factories\ReportTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['legacy_type', 'legacy_id', 'legacy_medico', 'legacy_procedure', 'nome', 'content_html', 'doctor_id', 'procedure_id', 'active', 'imported_at'])]
class ReportTemplate extends Model
{
    /** @use HasFactory<ReportTemplateFactory> */
    use HasFactory, HasUuids;

    public const TYPE_REPORT = 'LAUDO';

    public const TYPE_LINE = 'LINHA';

    protected function casts(): array
    {
        return ['active' => 'boolean', 'imported_at' => 'immutable_datetime'];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'doctor_id');
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }
}
