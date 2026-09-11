<?php

namespace App\Actions\Scheduling;

use App\Enums\PriceStatus;
use App\Models\ScheduleOccurrence;
use App\Models\ServiceOffering;
use App\Services\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Recomputa preço espelhado + visibilidade online de uma oferta.
 *
 * Regra (decisão do dono): oferta do convênio padrão com vínculo, preço e
 * escala válidos nasce visível no online (opt-out via `online_paused`);
 * sem vencimento; o preço espelha a origem vigente sozinho.
 */
class RefreshOfferingVisibility
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(ServiceOffering $offering): ServiceOffering
    {
        return DB::transaction(function () use ($offering): ServiceOffering {
            $offering = ServiceOffering::query()->with('procedurePrice')->lockForUpdate()->findOrFail($offering->id);
            $before = $offering->attributesToArray();

            $origin = $offering->procedurePrice;
            if ($origin && $origin->status === PriceStatus::Valid && (string) $offering->price !== (string) $origin->amount) {
                $offering->forceFill(['price' => $origin->amount]);
            }

            $eligible = ServiceOffering::query()->whereKey($offering->id)->eligibleForPublication()->exists();
            $visible = ! $offering->online_paused && $eligible && $this->hasPublicAvailability($offering);
            $offering->forceFill(['online_enabled' => $visible])->save();

            $after = $offering->attributesToArray();
            if ($before['price'] !== $after['price'] || (bool) $before['online_enabled'] !== (bool) $after['online_enabled']) {
                $this->audit->recordSystem($offering, 'visibility.refreshed', $before, $after);
            }

            return $offering;
        }, 3);
    }

    /**
     * Oferta publicável precisa de ao menos uma ocorrência aberta dentro do
     * horizonte online. A checagem por ocorrência substitui a construção da
     * disponibilidade completa (mesma janela) — chamada a cada mutação de
     * escala — por uma única consulta indexada.
     */
    private function hasPublicAvailability(ServiceOffering $offering): bool
    {
        if (! $offering->active) {
            return false;
        }
        $timezone = config('sistema_dez.timezone');
        $today = CarbonImmutable::today($timezone);

        return ScheduleOccurrence::query()
            ->where('service_offering_id', $offering->id)
            ->where('status', 'OPEN')
            ->whereBetween('date', [
                $today->toDateString(),
                $today->addDays(config('sistema_dez.public_booking_horizon_days') - 1)->toDateString(),
            ])
            ->exists();
    }
}
