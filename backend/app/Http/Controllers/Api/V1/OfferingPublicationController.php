<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Scheduling\BuildAvailability;
use App\Actions\Scheduling\MaterializeScheduleOccurrences;
use App\Http\Controllers\Controller;
use App\Models\ServiceOffering;
use App\Services\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OfferingPublicationController extends Controller
{
    public function update(Request $request, ServiceOffering $serviceOffering, BuildAvailability $availability, MaterializeScheduleOccurrences $materialize, AuditRecorder $audit): JsonResponse
    {
        $data = $request->validate(['published_until' => ['required', 'date_format:Y-m-d'], 'confirmed' => ['accepted']]);
        $today = CarbonImmutable::today(config('sistema_dez.timezone'));
        $until = CarbonImmutable::parse($data['published_until'], config('sistema_dez.timezone'));
        if ($until->lessThan($today) || $until->greaterThan($today->addDays(config('sistema_dez.public_booking_horizon_days') - 1))) {
            throw ValidationException::withMessages(['published_until' => 'Confirme uma data de publicação dentro dos próximos 30 dias.']);
        }
        $offering = DB::transaction(function () use ($request, $serviceOffering, $until, $today, $availability, $materialize, $audit): ServiceOffering {
            $offering = ServiceOffering::query()->lockForUpdate()->findOrFail($serviceOffering->id);
            if (! ServiceOffering::query()->whereKey($offering->id)->eligibleForPublication()->exists()) {
                throw ValidationException::withMessages(['publication' => 'Revise o vínculo ativo, a unidade operacional e o preço vigente. Procedimento, profissional e convênio precisam estar habilitados online; convênios que exigem guia não podem ser publicados.']);
            }
            if ($offering->price !== $offering->procedurePrice->amount) {
                throw ValidationException::withMessages(['publication' => 'O preço de origem mudou. Salve a oferta com o preço vigente antes de publicar.']);
            }
            $materialize->handle($offering, $today, $until);
            $rows = $availability->handle(['internal' => true, 'offering_id' => $offering->id, 'procedure_id' => $offering->procedure_id,
                'unit_id' => $offering->unit_id, 'agreement_id' => $offering->agreement_id, 'modality' => $offering->modality->value,
                'from' => $today->toDateString(), 'to' => $until->toDateString()]);
            if (count($rows) === 0) {
                throw ValidationException::withMessages(['publication' => 'Configure uma escala com disponibilidade no período de publicação.']);
            }
            $before = $offering->attributesToArray();
            $offering->forceFill(['online_enabled' => true, 'published_at' => now(), 'published_until' => $until])->save();
            $audit->record($request, $offering, 'offering.published', $before, $offering->attributesToArray());

            return $offering;
        }, 3);

        return response()->json(['data' => $offering]);
    }

    public function destroy(Request $request, ServiceOffering $serviceOffering, AuditRecorder $audit): JsonResponse
    {
        $offering = DB::transaction(function () use ($request, $serviceOffering, $audit): ServiceOffering {
            $offering = ServiceOffering::query()->lockForUpdate()->findOrFail($serviceOffering->id);
            $before = $offering->attributesToArray();
            $offering->forceFill(['online_enabled' => false, 'published_until' => null])->save();
            $audit->record($request, $offering, 'offering.unpublished', $before, $offering->attributesToArray());

            return $offering;
        }, 3);

        return response()->json(['data' => $offering]);
    }
}
