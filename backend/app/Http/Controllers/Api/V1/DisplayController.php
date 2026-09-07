<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\QueueEntry;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DisplayController extends Controller
{
    public function units(): JsonResponse
    {
        $units = Unit::query()->where('active', true)->where('operational_enabled', true)
            ->orderBy('name')->get(['id', 'name']);

        return response()->json(['data' => $units]);
    }

    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'unit_id' => ['required', 'uuid', 'exists:units,id'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $unit = Unit::query()->whereKey($data['unit_id'])->where('active', true)->where('operational_enabled', true)->first();
        abort_unless($unit, 422, 'Unidade fora da operação.');
        $date = $data['date'] ?? CarbonImmutable::today((string) config('sistema_dez.timezone'))->toDateString();

        $current = QueueEntry::query()
            ->with(['appointment.patient:id,name', 'serviceOffering.procedure:id,name', 'serviceOffering.professional:id,name'])
            ->where('unit_id', $unit->id)->whereDate('service_date', $date)
            ->whereNotNull('called_at')
            ->orderByDesc('called_at')->first();

        $next = QueueEntry::query()
            ->with(['appointment.patient:id,name', 'serviceOffering.procedure:id,name', 'serviceOffering.professional:id,name'])
            ->where('unit_id', $unit->id)->whereDate('service_date', $date)
            ->where('status', 'WAITING')
            ->orderByRaw('priority_rank NULLS LAST')->orderBy('original_sequence')
            ->limit(3)->get();

        return response()->json(['data' => [
            'unit' => ['id' => $unit->id, 'name' => $unit->name],
            'date' => $date,
            'current' => $current ? $this->serialize($current) : null,
            'next' => $next->map(fn (QueueEntry $entry): array => $this->serialize($entry))->all(),
        ]]);
    }

    /** @return array<string, mixed> */
    private function serialize(QueueEntry $entry): array
    {
        $fullName = trim((string) $entry->appointment?->patient?->name);

        return [
            'ticket' => 'A'.str_pad((string) $entry->original_sequence, 3, '0', STR_PAD_LEFT),
            'first_name' => $fullName === '' ? null : explode(' ', $fullName)[0],
            'procedure_name' => $entry->serviceOffering->procedure?->name,
            'professional_name' => $entry->serviceOffering->professional?->name,
            'called_at' => $entry->called_at?->toIso8601String(),
        ];
    }
}
