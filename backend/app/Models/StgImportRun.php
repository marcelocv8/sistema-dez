<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['checksum', 'status', 'summary', 'started_at', 'completed_at'])]
class StgImportRun extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['summary' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function issues(): HasMany
    {
        return $this->hasMany(StgImportIssue::class, 'import_run_id');
    }
}
