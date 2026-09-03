<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Scheduling\MaterializeScheduleOccurrences;
use App\Actions\Scheduling\RefreshOfferingVisibility;
use App\Enums\BookingMode;
use App\Http\Controllers\Controller;
use App\Models\ScheduleWindow;
use App\Models\ServiceOffering;
use App\Services\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleWindowController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit, private readonly MaterializeScheduleOccurrences $materialize, private readonly RefreshOfferingVisibility $visibility) {}

    public function index(ServiceOffering $serviceOffering): JsonResponse
    {
        return response()->json(['data' => $serviceOffering->scheduleWindows()->orderBy('weekday')->orderBy('starts_at')->get()]);
    }

    public function store(Request $request, ServiceOffering $serviceOffering): JsonResponse
    {
        return $this->save($request, $serviceOffering, new ScheduleWindow);
    }

    public function update(Request $request, ServiceOffering $serviceOffering, ScheduleWindow $scheduleWindow): JsonResponse
    {
        abort_unless($scheduleWindow->service_offering_id === $serviceOffering->id, 404);

        return $this->save($request, $serviceOffering, $scheduleWindow);
    }

    private function save(Request $request, ServiceOffering $offering, ScheduleWindow $window): JsonResponse
    {
        $data = $request->validate([
            'effective_from' => ['required', 'date_format:Y-m-d'], 'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'weekday' => ['required', 'integer', 'between:0,6'], 'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'slot_minutes' => ['nullable', 'integer', 'between:1,480'], 'daily_capacity_override' => ['nullable', 'integer', 'between:1,1000'],
            'active' => ['required', 'boolean'],
        ]);
        $created = ! $window->exists;
        DB::transaction(function () use ($request, $offering, $window, $data): void {
            $offering = ServiceOffering::query()->lockForUpdate()->findOrFail($offering->id);
            abort_unless($offering->unit->operational_enabled, 422, 'Unidade fora da operação.');
            $minutes = $offering->booking_mode === BookingMode::ArrivalBlock ? $offering->arrival_block_minutes
                : ($data['slot_minutes'] ?? $offering->duration_override_minutes ?? $offering->procedure->duration_minutes);
            $windowMinutes = CarbonImmutable::parse($data['starts_at'])->diffInMinutes(CarbonImmutable::parse($data['ends_at']));
            if ($offering->booking_mode !== BookingMode::WalkInInfo && $minutes > $windowMinutes) {
                throw ValidationException::withMessages(['ends_at' => 'A janela precisa comportar pelo menos uma vaga ou bloco.']);
            }
            $overlap = $offering->scheduleWindows()->where('active', true)->where('weekday', $data['weekday'])
                ->when($window->exists, fn ($query) => $query->where('id', '!=', $window->id))
                ->where('starts_at', '<', $data['ends_at'])->where('ends_at', '>', $data['starts_at'])
                ->where('effective_from', '<=', $data['effective_to'] ?? '9999-12-31')
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $data['effective_from']))->exists();
            if ($data['active'] && $overlap) {
                throw ValidationException::withMessages(['starts_at' => 'Esta escala se sobrepõe a outra janela ativa.']);
            }
            $before = $window->exists ? $window->attributesToArray() : null;
            $window->fill([...$data, 'service_offering_id' => $offering->id])->save();
            $this->materialize->handle($offering);
            $this->visibility->handle($offering);
            $this->audit->record($request, $window, $before ? 'updated' : 'created', $before, $window->attributesToArray());
        }, 3);

        return response()->json(['data' => $window->fresh()], $created ? 201 : 200);
    }

    public function destroy(Request $request, ServiceOffering $serviceOffering, ScheduleWindow $scheduleWindow): JsonResponse
    {
        abort_unless($scheduleWindow->service_offering_id === $serviceOffering->id, 404);
        DB::transaction(function () use ($request, $serviceOffering, $scheduleWindow): void {
            ServiceOffering::query()->whereKey($serviceOffering->id)->lockForUpdate()->firstOrFail();
            $before = $scheduleWindow->attributesToArray();
            $scheduleWindow->update(['active' => false]);
            $this->materialize->handle($serviceOffering);
            $this->visibility->handle($serviceOffering);
            $this->audit->record($request, $scheduleWindow, 'deactivated', $before, $scheduleWindow->attributesToArray());
        });

        return response()->json(['data' => $scheduleWindow->fresh()]);
    }
}
