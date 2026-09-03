<?php

namespace App\Models;

use App\Enums\BookingMode;
use App\Enums\Modality;
use Database\Factories\ServiceOfferingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['catalog_link_id', 'unit_id', 'procedure_id', 'professional_id', 'agreement_id', 'procedure_price_id', 'booking_mode', 'modality', 'price', 'duration_override_minutes', 'daily_capacity', 'arrival_block_minutes', 'arrival_block_capacity', 'online_enabled', 'active'])]
class ServiceOffering extends Model
{
    /** @use HasFactory<ServiceOfferingFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'booking_mode' => BookingMode::class,
            'modality' => Modality::class,
            'price' => 'decimal:2',
            'online_enabled' => 'boolean',
            'active' => 'boolean',
            'published_until' => 'immutable_date:Y-m-d',
            'published_at' => 'immutable_datetime',
        ];
    }

    public function scopePubliclyAvailable(Builder $query): void
    {
        $query->where('active', true)->where('online_enabled', true)
            ->where('published_until', '>=', now(config('sistema_dez.timezone'))->toDateString())
            ->eligibleForPublication();
    }

    public function scopeEligibleForPublication(Builder $query): void
    {
        $today = now(config('sistema_dez.timezone'))->toDateString();
        $query->where('active', true)
            ->whereHas('unit', fn (Builder $query) => $query->where('active', true)->where('operational_enabled', true))
            ->whereHas('procedure', fn (Builder $query) => $query->where('active', true)->where('online_enabled', true))
            ->whereHas('professional', fn (Builder $query) => $query->where('active', true)->where('online_enabled', true))
            ->whereHas('agreement', fn (Builder $query) => $query->where('active', true)->where('online_enabled', true)->where('requires_authorization', false))
            ->whereHas('catalogLink', fn (Builder $query) => $query->where('active', true)
                ->whereColumn('catalog_links.unit_id', 'service_offerings.unit_id')
                ->whereColumn('catalog_links.procedure_id', 'service_offerings.procedure_id')
                ->whereColumn('catalog_links.professional_id', 'service_offerings.professional_id')
                ->whereColumn('catalog_links.agreement_id', 'service_offerings.agreement_id'))
            ->whereHas('procedurePrice', fn (Builder $query) => $query->where('status', 'VALID')
                ->whereColumn('procedure_prices.procedure_id', 'service_offerings.procedure_id')
                ->whereColumn('procedure_prices.agreement_id', 'service_offerings.agreement_id')
                ->where(fn ($query) => $query->whereNull('unit_id')->orWhereColumn('procedure_prices.unit_id', 'service_offerings.unit_id'))
                ->where(fn ($query) => $query->whereNull('valid_from')->orWhere('valid_from', '<=', $today))
                ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>=', $today)));
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function catalogLink(): BelongsTo
    {
        return $this->belongsTo(CatalogLink::class);
    }

    public function procedurePrice(): BelongsTo
    {
        return $this->belongsTo(ProcedurePrice::class);
    }

    public function scheduleOccurrences(): HasMany
    {
        return $this->hasMany(ScheduleOccurrence::class);
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    public function scheduleWindows(): HasMany
    {
        return $this->hasMany(ScheduleWindow::class);
    }

    public function scheduleExceptions(): HasMany
    {
        return $this->hasMany(ScheduleException::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }
}
