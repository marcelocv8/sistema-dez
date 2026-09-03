<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['import_run_id', 'code', 'entity_type', 'legacy_id', 'source_file', 'payload', 'resolution_status', 'resolution_note', 'resolved_by', 'resolved_at'])]
class StgImportIssue extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['payload' => 'array', 'resolved_at' => 'immutable_datetime'];
    }

    public function importRun(): BelongsTo
    {
        return $this->belongsTo(StgImportRun::class, 'import_run_id');
    }
}
