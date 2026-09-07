<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\ScheduleConflict;
use App\Models\ServiceOffering;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Detecta agendamentos afetados por mudanças de escala e registra a
 * pendência para a recepção resolver com médico/paciente (nunca apaga
 * silenciosamente).
 */
class ScheduleConflictDetector
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array{from: CarbonImmutable, to: CarbonImmutable, weekday: int|null, starts_at: string|null, ends_at: string|null, exclude?: array{weekday: int|null, starts_at: string|null, ends_at: string|null}}  $scope
     * @return array{conflict_id: string|null, affected: list<array<string, mixed>>}
     */
    public function detect(Request $request, ServiceOffering $offering, array $scope, string $reason, ?string $windowId = null, ?string $exceptionId = null): array
    {
        return DB::transaction(function () use ($request, $offering, $scope, $reason, $windowId, $exceptionId): array {
            $timezone = (string) config('sistema_dez.timezone');
            $candidates = Appointment::query()
                ->where('service_offering_id', $offering->id)
                ->whereIn('status', ['CONFIRMED', 'CHECKED_IN'])
                ->whereBetween('scheduled_at', [$scope['from']->utc(), $scope['to']->utc()])
                ->with('patient:id,name')->get()
                ->filter(function (Appointment $appointment) use ($scope, $timezone): bool {
                    $local = $appointment->scheduled_at->setTimezone($timezone);
                    if (! $this->inRange($local, $scope['weekday'], $scope['starts_at'], $scope['ends_at'])) {
                        return false;
                    }
                    // Encolhimento: quem continua coberto pela faixa nova não é afetado.
                    $exclude = $scope['exclude'] ?? null;
                    if ($exclude !== null && $this->inRange($local, $exclude['weekday'], $exclude['starts_at'], $exclude['ends_at'])) {
                        return false;
                    }

                    return true;
                })->values();

            if ($candidates->isEmpty()) {
                return ['conflict_id' => null, 'affected' => []];
            }

            $affected = $candidates->map(fn (Appointment $appointment): array => [
                'appointment_id' => $appointment->id,
                'scheduled_at' => $appointment->scheduled_at->setTimezone($timezone)->toIso8601String(),
                'patient_name' => $appointment->patient?->name,
                'status' => $appointment->status,
            ])->all();

            $conflict = ScheduleConflict::query()->create([
                'service_offering_id' => $offering->id,
                'schedule_window_id' => $windowId,
                'schedule_exception_id' => $exceptionId,
                'reason' => $reason,
                'affected' => $affected,
                'status' => 'OPEN',
                'created_by' => $request->user()->id,
            ]);
            $this->audit->record($request, $conflict, 'schedule.conflict_opened', null, $conflict->attributesToArray());

            return ['conflict_id' => $conflict->id, 'affected' => $affected];
        }, 3);
    }

    private function inRange(CarbonImmutable $local, ?int $weekday, ?string $startsAt, ?string $endsAt): bool
    {
        if ($weekday !== null && $local->dayOfWeek !== $weekday) {
            return false;
        }
        if ($startsAt !== null && $endsAt !== null) {
            $time = $local->format('H:i:s');

            return $time < $endsAt && $time >= $startsAt;
        }

        return true;
    }
}
