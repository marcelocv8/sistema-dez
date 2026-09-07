<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\QueuePriority;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\QueueEntry;
use App\Models\ScheduleConflict;
use App\Services\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QueueController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit) {}

    private function clinicDate(): string
    {
        return CarbonImmutable::today((string) config('sistema_dez.timezone'))->toDateString();
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'unit_id' => ['nullable', 'uuid'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $date = $data['date'] ?? $this->clinicDate();

        $entries = QueueEntry::query()
            ->with([
                'appointment.patient:id,name',
                'appointment.paymentMethod:id,code,name',
                'serviceOffering.procedure:id,name',
                'serviceOffering.professional:id,name',
                'serviceOffering.unit:id,name',
            ])
            ->when($data['unit_id'] ?? null, fn ($query, $id) => $query->where('unit_id', $id))
            ->whereDate('service_date', $date)
            ->get();

        $ordered = $entries->sortBy([
            fn (QueueEntry $a, QueueEntry $b): int => ($a->priority_rank ?? 999) <=> ($b->priority_rank ?? 999),
            fn (QueueEntry $a, QueueEntry $b): int => $a->original_sequence <=> $b->original_sequence,
        ])->values();
        $positions = $ordered->mapWithKeys(fn (QueueEntry $entry, int $index): array => [$entry->id => $index + 1]);

        return response()->json(['data' => [
            'date' => $date,
            'queue' => $ordered->map(fn (QueueEntry $entry) => $this->serialize($entry, $positions[$entry->id]))->all(),
            'priorities' => array_map(fn (QueuePriority $case): array => ['code' => $case->value, 'label' => $case->label()], QueuePriority::cases()),
        ]]);
    }

    public function reorder(Request $request, QueueEntry $queueEntry): JsonResponse
    {
        $data = $request->validate([
            'priority_code' => ['nullable', Rule::in(QueuePriority::codes())],
            'priority_reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $entry = DB::transaction(function () use ($request, $queueEntry, $data): QueueEntry {
            $entry = QueueEntry::query()->lockForUpdate()->findOrFail($queueEntry->id);
            $before = $entry->attributesToArray();
            $rank = $data['priority_code'] === null ? null : QueuePriority::from($data['priority_code'])->rank();
            $entry->forceFill([
                'priority_code' => $data['priority_code'],
                'priority_rank' => $rank,
                'priority_reason' => $data['priority_reason'],
                'reordered_by' => $request->user()->id,
            ])->save();
            $this->audit->record($request, $entry, 'queue.reordered', $before, $entry->attributesToArray());

            return $entry;
        }, 3);

        return response()->json(['data' => $this->serialize($entry->load(['appointment.patient:id,name', 'serviceOffering.procedure:id,name', 'serviceOffering.professional:id,name', 'serviceOffering.unit:id,name']), 0)]);
    }

    public function call(Request $request, QueueEntry $queueEntry): JsonResponse
    {
        $entry = DB::transaction(function () use ($request, $queueEntry): QueueEntry {
            $entry = QueueEntry::query()->with('appointment.serviceOffering.professional')->lockForUpdate()->findOrFail($queueEntry->id);
            if (! in_array($entry->status, ['WAITING', 'CALLED'], true)) {
                throw ValidationException::withMessages(['queue' => 'Somente fichas aguardando na fila podem ser chamadas.']);
            }
            $user = $request->user();
            if ($user->role === UserRole::Doctor) {
                $professionalUserId = $entry->appointment->serviceOffering->professional?->user_id;
                if ($professionalUserId === null || (int) $professionalUserId !== (int) $user->id) {
                    throw ValidationException::withMessages(['queue' => 'O médico só pode chamar os próprios pacientes do dia.']);
                }
            }
            $before = $entry->attributesToArray();
            $entry->forceFill(['status' => 'CALLED', 'called_at' => now(), 'called_by' => $user->id])->save();
            $this->audit->record($request, $entry, 'queue.called', $before, $entry->attributesToArray());

            return $entry;
        }, 3);

        return response()->json(['data' => $this->serialize($entry->load(['appointment.patient:id,name', 'serviceOffering.procedure:id,name', 'serviceOffering.professional:id,name', 'serviceOffering.unit:id,name']), 0)]);
    }

    public function history(Request $request): JsonResponse
    {
        $data = $request->validate([
            'unit_id' => ['nullable', 'uuid'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $date = $data['date'] ?? $this->clinicDate();

        $calls = QueueEntry::query()
            ->with(['appointment.patient:id,name', 'serviceOffering.procedure:id,name', 'serviceOffering.professional:id,name'])
            ->when($data['unit_id'] ?? null, fn ($query, $id) => $query->where('unit_id', $id))
            ->whereDate('service_date', $date)
            ->whereNotNull('called_at')
            ->orderByDesc('called_at')->limit(50)->get();

        return response()->json(['data' => $calls->map(fn (QueueEntry $entry): array => [
            'id' => $entry->id,
            'ticket' => $this->ticket($entry),
            'patient_name' => $entry->appointment?->patient?->name,
            'procedure_name' => $entry->serviceOffering->procedure?->name,
            'professional_name' => $entry->serviceOffering->professional?->name,
            'called_at' => $entry->called_at?->toIso8601String(),
        ])->all()]);
    }

    public function conflicts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['OPEN', 'RESOLVED'])],
            'service_offering_id' => ['nullable', 'uuid'],
        ]);

        $conflicts = ScheduleConflict::query()->with(['serviceOffering.procedure:id,name', 'serviceOffering.professional:id,name'])
            ->when($data['service_offering_id'] ?? null, fn ($query, $id) => $query->where('service_offering_id', $id))
            ->where('status', $data['status'] ?? 'OPEN')
            ->orderByDesc('created_at')->limit(100)->get();

        return response()->json(['data' => $conflicts->map(fn (ScheduleConflict $conflict): array => [
            'id' => $conflict->id,
            'service_offering_id' => $conflict->service_offering_id,
            'procedure_name' => $conflict->serviceOffering->procedure?->name,
            'professional_name' => $conflict->serviceOffering->professional?->name,
            'reason' => $conflict->reason,
            'affected' => $conflict->affected,
            'affected_count' => is_array($conflict->affected) ? count($conflict->affected) : 0,
            'status' => $conflict->status,
            'created_at' => $conflict->created_at?->toIso8601String(),
            'resolution_note' => $conflict->resolution_note,
            'resolved_at' => $conflict->resolved_at?->toIso8601String(),
        ])->all()]);
    }

    public function resolveConflict(Request $request, ScheduleConflict $scheduleConflict): JsonResponse
    {
        $data = $request->validate(['resolution_note' => ['required', 'string', 'min:3', 'max:1000']]);

        $conflict = DB::transaction(function () use ($request, $scheduleConflict, $data): ScheduleConflict {
            $conflict = ScheduleConflict::query()->lockForUpdate()->findOrFail($scheduleConflict->id);
            if ($conflict->status !== 'OPEN') {
                throw ValidationException::withMessages(['conflict' => 'Esta pendência já foi resolvida.']);
            }
            $before = $conflict->attributesToArray();
            $conflict->forceFill([
                'status' => 'RESOLVED', 'resolution_note' => $data['resolution_note'],
                'resolved_by' => $request->user()->id, 'resolved_at' => now(),
            ])->save();
            $this->audit->record($request, $conflict, 'schedule.conflict_resolved', $before, $conflict->attributesToArray());

            return $conflict;
        }, 3);

        return response()->json(['data' => $conflict]);
    }

    /** @return array<string, mixed> */
    private function serialize(QueueEntry $entry, int $position): array
    {
        $priority = $entry->priority_code === null ? null : QueuePriority::tryFrom($entry->priority_code);

        return [
            'id' => $entry->id,
            'appointment_id' => $entry->appointment_id,
            'ticket' => $this->ticket($entry),
            'suggested_position' => $position,
            'original_sequence' => $entry->original_sequence,
            'priority_code' => $entry->priority_code,
            'priority_label' => $priority?->label(),
            'priority_rank' => $entry->priority_rank,
            'priority_reason' => $entry->priority_reason,
            'status' => $entry->status,
            'checked_in_at' => $entry->checked_in_at?->toIso8601String(),
            'called_at' => $entry->called_at?->toIso8601String(),
            'patient_name' => $entry->appointment?->patient?->name,
            'payment_status' => $entry->appointment?->payment_status,
            'payment_method' => $entry->appointment?->paymentMethod?->only(['code', 'name']),
            'procedure_name' => $entry->serviceOffering->procedure?->name,
            'professional_name' => $entry->serviceOffering->professional?->name,
            'unit_name' => $entry->serviceOffering->unit?->name,
        ];
    }

    private function ticket(QueueEntry $entry): string
    {
        return 'A'.str_pad((string) $entry->original_sequence, 3, '0', STR_PAD_LEFT);
    }
}
