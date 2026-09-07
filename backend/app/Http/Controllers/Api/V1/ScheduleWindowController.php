<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Scheduling\MaterializeScheduleOccurrences;
use App\Actions\Scheduling\RefreshOfferingVisibility;
use App\Enums\BookingMode;
use App\Http\Controllers\Controller;
use App\Models\ScheduleWindow;
use App\Models\ServiceOffering;
use App\Services\AuditRecorder;
use App\Services\ScheduleConflictDetector;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleWindowController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit, private readonly MaterializeScheduleOccurrences $materialize, private readonly RefreshOfferingVisibility $visibility, private readonly ScheduleConflictDetector $conflicts) {}

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
        $report = DB::transaction(function () use ($request, $offering, $window, $data): array {
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
            $narrowed = $before !== null && $this->narrowed($before, $data);
            $window->fill([...$data, 'service_offering_id' => $offering->id])->save();
            $this->materialize->handle($offering);
            $this->visibility->handle($offering);
            $this->audit->record($request, $window, $before ? 'updated' : 'created', $before, $window->attributesToArray());

            $detection = ['conflict_id' => null, 'affected' => []];
            if ($narrowed || ($before !== null && ! $data['active'])) {
                $timezone = (string) config('sistema_dez.timezone');
                $today = CarbonImmutable::today($timezone);
                $time = fn ($value): string => substr((string) $value, 0, 5).':00';
                $from = CarbonImmutable::parse($before['effective_from'], $timezone)->greaterThan($today) ? CarbonImmutable::parse($before['effective_from'], $timezone) : $today;
                $to = $before['effective_to'] !== null ? CarbonImmutable::parse($before['effective_to'], $timezone)->endOfDay() : $today->addDays(89)->endOfDay();
                $scope = [
                    'from' => $from->startOfDay(), 'to' => $to,
                    'weekday' => (int) $before['weekday'], 'starts_at' => $time($before['starts_at']), 'ends_at' => $time($before['ends_at']),
                ];
                if ($data['active']) {
                    $scope['exclude'] = [
                        'weekday' => (int) $data['weekday'], 'starts_at' => $time($data['starts_at']), 'ends_at' => $time($data['ends_at']),
                    ];
                }
                $detection = $this->conflicts->detect($request, $offering, $scope, "Escala {$this->weekdayLabel((int) $before['weekday'])} {$time($before['starts_at'])}–{$time($before['ends_at'])} alterada com agendamentos existentes.", $window->id);
            }

            return $detection;
        }, 3);

        return response()->json(['data' => $window->fresh(), 'conflict_id' => $report['conflict_id'], 'conflicts' => $report['affected']], $created ? 201 : 200);
    }

    public function destroy(Request $request, ServiceOffering $serviceOffering, ScheduleWindow $scheduleWindow): JsonResponse
    {
        abort_unless($scheduleWindow->service_offering_id === $serviceOffering->id, 404);
        $report = DB::transaction(function () use ($request, $serviceOffering, $scheduleWindow): array {
            ServiceOffering::query()->whereKey($serviceOffering->id)->lockForUpdate()->firstOrFail();
            $before = $scheduleWindow->attributesToArray();
            $scheduleWindow->update(['active' => false]);
            $this->materialize->handle($serviceOffering);
            $this->visibility->handle($serviceOffering);
            $this->audit->record($request, $scheduleWindow, 'deactivated', $before, $scheduleWindow->attributesToArray());

            $timezone = (string) config('sistema_dez.timezone');
            $today = CarbonImmutable::today($timezone);
            $to = $before['effective_to'] !== null ? CarbonImmutable::parse($before['effective_to'], $timezone)->endOfDay() : $today->addDays(89)->endOfDay();
            if (! $before['active']) {
                return ['conflict_id' => null, 'affected' => []];
            }

            return $this->conflicts->detect($request, $serviceOffering, [
                'from' => $today->startOfDay(), 'to' => $to,
                'weekday' => (int) $before['weekday'], 'starts_at' => substr((string) $before['starts_at'], 0, 5), 'ends_at' => substr((string) $before['ends_at'], 0, 5),
            ], "Escala {$this->weekdayLabel((int) $before['weekday'])} removida com agendamentos existentes.", $scheduleWindow->id);
        });

        return response()->json(['data' => $scheduleWindow->fresh(), 'conflict_id' => $report['conflict_id'], 'conflicts' => $report['affected']]);
    }

    public function import(Request $request, ServiceOffering $serviceOffering): JsonResponse
    {
        $data = $request->validate([
            'dry_run' => ['nullable', 'boolean'],
            'rows' => ['required', 'array', 'min:1', 'max:200'],
            'rows.*.effective_from' => ['required', 'date_format:Y-m-d'],
            'rows.*.effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:rows.*.effective_from'],
            'rows.*.weekday' => ['required', 'integer', 'between:0,6'],
            'rows.*.starts_at' => ['required', 'date_format:H:i'],
            'rows.*.ends_at' => ['required', 'date_format:H:i', 'after:rows.*.starts_at'],
            'rows.*.slot_minutes' => ['nullable', 'integer', 'between:1,480'],
            'rows.*.daily_capacity_override' => ['nullable', 'integer', 'between:1,1000'],
        ]);

        $report = DB::transaction(function () use ($request, $serviceOffering, $data): array {
            $offering = ServiceOffering::query()->lockForUpdate()->findOrFail($serviceOffering->id);
            abort_unless($offering->unit->operational_enabled, 422, 'Unidade fora da operação.');
            $errors = [];
            foreach ($data['rows'] as $index => $row) {
                $conflict = $offering->scheduleWindows()->where('active', true)->where('weekday', $row['weekday'])
                    ->where('starts_at', '<', $row['ends_at'])->where('ends_at', '>', $row['starts_at'])
                    ->where('effective_from', '<=', $row['effective_to'] ?? '9999-12-31')
                    ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $row['effective_from']))->exists();
                if ($conflict) {
                    $errors[$index] = ['A linha se sobrepõe a uma janela ativa existente.'];

                    continue;
                }
                foreach ($data['rows'] as $other => $sibling) {
                    if ($other >= $index || (int) $sibling['weekday'] !== (int) $row['weekday']) {
                        continue;
                    }
                    $siblingTo = $sibling['effective_to'] ?? '9999-12-31';
                    $rowTo = $row['effective_to'] ?? '9999-12-31';
                    $siblingFrom = $sibling['effective_from'];
                    if ($row['starts_at'] < $sibling['ends_at'] && $row['ends_at'] > $sibling['starts_at']
                        && $row['effective_from'] <= $siblingTo && $rowTo >= $siblingFrom) {
                        $errors[$index] = ['A linha se sobrepõe à linha '.($other + 1).' da planilha.'];
                        break;
                    }
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages(['rows' => 'Algumas linhas conflitam; nada foi importado.', ...$errors]);
            }
            $created = 0;
            if (! ($data['dry_run'] ?? false)) {
                foreach ($data['rows'] as $row) {
                    $offering->scheduleWindows()->create([...$row, 'active' => true]);
                    $created++;
                }
                $this->materialize->handle($offering);
                $this->visibility->handle($offering);
                $this->audit->record($request, $offering, 'schedule.imported', null, ['created' => $created, 'source' => 'csv']);
            }

            return ['created' => $data['dry_run'] ?? false ? 0 : $created, 'validated' => count($data['rows']), 'dry_run' => (bool) ($data['dry_run'] ?? false)];
        }, 3);

        return response()->json(['data' => $report], 201);
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    private function narrowed(array $before, array $after): bool
    {
        $time = fn ($value): string => substr((string) $value, 0, 5);

        return $time($after['starts_at']) > $time($before['starts_at'])
            || $time($after['ends_at']) < $time($before['ends_at'])
            || ($after['effective_to'] !== null && ($before['effective_to'] === null || $after['effective_to'] < $before['effective_to']));
    }

    private function weekdayLabel(int $weekday): string
    {
        return ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'][$weekday] ?? "dia {$weekday}";
    }
}
