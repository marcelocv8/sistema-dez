<?php

namespace App\Models;

use App\Enums\PriceStatus;
use Database\Factories\ProcedurePriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['procedure_id', 'agreement_id', 'unit_id', 'nature', 'amount', 'valid_from', 'valid_until', 'status', 'source_file', 'source_key', 'source_checksum'])]
class ProcedurePrice extends Model
{
    /** @use HasFactory<ProcedurePriceFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'status' => PriceStatus::class,
        ];
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
