<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['b2b_invoice_id', 'appointment_id', 'scheduled_at', 'patient_name', 'procedure_name', 'gross_amount'])]
class B2bInvoiceItem extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['scheduled_at' => 'immutable_datetime', 'gross_amount' => 'decimal:2'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(B2bInvoice::class, 'b2b_invoice_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
