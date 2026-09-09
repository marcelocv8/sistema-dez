<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\B2bInvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['agreement_id', 'competence', 'status', 'subtotal_gross', 'settled_amount', 'issued_at', 'due_at', 'sent_at', 'settled_at', 'proof_reference', 'created_by'])]
class B2bInvoice extends Model
{
    /** @use HasFactory<B2bInvoiceFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'competence' => 'immutable_date:Y-m-d',
            'subtotal_gross' => 'decimal:2',
            'settled_amount' => 'decimal:2',
            'issued_at' => 'immutable_datetime',
            'due_at' => 'immutable_date:Y-m-d',
            'sent_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
        ];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(B2bInvoiceItem::class);
    }

    public function remainingAmount(): string
    {
        return bcsub((string) $this->subtotal_gross, (string) $this->settled_amount, 2);
    }

    public function isOverdue(?CarbonImmutable $today = null): bool
    {
        $today ??= CarbonImmutable::today(config('sistema_dez.timezone'));

        return $this->status !== 'PAGA'
            && $this->due_at->startOfDay()->lessThan($today->startOfDay())
            && bccomp($this->remainingAmount(), '0.00', 2) > 0;
    }

    public function computedStatus(?CarbonImmutable $today = null): string
    {
        return $this->isOverdue($today) ? 'VENCIDA' : $this->status;
    }
}
