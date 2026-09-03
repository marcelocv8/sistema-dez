<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Scheduling\BuildAvailability;
use App\Actions\Scheduling\MaterializeScheduleOccurrences;
use App\Http\Controllers\Controller;
use App\Models\ServiceOffering;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeamAvailabilityController extends Controller
{
    public function __invoke(Request $request, BuildAvailability $availability, MaterializeScheduleOccurrences $materialize): JsonResponse
    {
        $data = $request->validate([
            'offering_id' => ['required', 'uuid'], 'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'], 'exclude_appointment_id' => ['nullable', 'uuid'],
        ]);
        $offering = ServiceOffering::query()->with('unit')->findOrFail($data['offering_id']);
        abort_unless($offering->unit->active && $offering->unit->operational_enabled, 422, 'Unidade fora da operação.');
        $from = CarbonImmutable::parse($data['from'], $offering->unit->timezone);
        $to = CarbonImmutable::parse($data['to'], $offering->unit->timezone);
        abort_if($from->lessThan(CarbonImmutable::today($offering->unit->timezone)), 422, 'Escolha uma data a partir de hoje.');
        abort_if($from->diffInDays($to) > 89, 422, 'Consulte no máximo 90 dias.');
        abort_if($to->greaterThan(CarbonImmutable::today($offering->unit->timezone)->addDays(89)->endOfDay()), 422, 'Disponibilidade limitada aos próximos 90 dias.');
        $materialize->handle($offering, $from, $to);
        $rows = $availability->handle([...$data, 'internal' => true, 'procedure_id' => $offering->procedure_id,
            'unit_id' => $offering->unit_id, 'agreement_id' => $offering->agreement_id, 'modality' => $offering->modality->value,
            'professional_id' => $offering->professional_id]);
        $open = $offering->scheduleOccurrences()->where('status', 'OPEN')->whereBetween('date', [$data['from'], $data['to']])->get()
            ->mapWithKeys(fn ($occurrence) => [$occurrence->date->toDateString().' '.$occurrence->starts_at => true]);
        foreach ($rows as &$row) {
            $row['slots'] = array_values(array_filter($row['slots'], fn ($slot) => $open->has(CarbonImmutable::parse($slot['starts_at'])->setTimezone($offering->unit->timezone)->format('Y-m-d H:i:s'))));
        }

        return response()->json(['data' => $rows]);
    }
}
