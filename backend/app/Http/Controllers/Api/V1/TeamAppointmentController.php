<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Scheduling\BookAppointment;
use App\Actions\Scheduling\ChangeAppointment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePublicAppointmentRequest;
use App\Models\Appointment;
use App\Models\Patient;
use App\Services\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeamAppointmentController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date', 'date_format:Y-m-d'],
            'professional_id' => ['nullable', 'uuid'],
            'unit_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', Rule::in(['CONFIRMED', 'CHECKED_IN', 'DONE', 'CANCELLED'])],
            'payment_status' => ['nullable', Rule::in(['PENDING', 'PAID', 'AUTHORIZED'])],
        ]);

        $date = $validated['date'] ?? today()->toDateString();
        $professionalId = $validated['professional_id'] ?? null;
        $from = CarbonImmutable::parse($validated['from'] ?? $date, config('sistema_dez.timezone'))->startOfDay();
        $to = CarbonImmutable::parse($validated['to'] ?? $validated['from'] ?? $date, config('sistema_dez.timezone'))->endOfDay();
        abort_if($from->diffInDays($to) > 90, 422, 'Consulte no máximo 90 dias.');

        $appointments = Appointment::query()
            ->whereHas('serviceOffering.unit', fn ($query) => $query->where('operational_enabled', true))
            ->with([
                'serviceOffering.procedure:id,name,group_name,duration_minutes',
                'serviceOffering.professional:id,name',
                'patient:id,name',
                'serviceOffering.unit',
                'serviceOffering.agreement',
                'scheduleOccurrence',
                'paymentMethod:id,code,name',
                'cashMovement:id,receipt_number',
            ])
            ->when($professionalId, fn ($query) => $query->whereHas(
                'serviceOffering',
                fn ($offering) => $offering->where('professional_id', $professionalId),
            ))
            ->when($validated['unit_id'] ?? null, fn ($query, $id) => $query->whereHas('serviceOffering', fn ($offering) => $offering->where('unit_id', $id)))
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['payment_status'] ?? null, fn ($query, $status) => $query->where('payment_status', $status))
            ->whereBetween('scheduled_at', [$from->utc(), $to->utc()])
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get();

        return new JsonResponse(['data' => [
            'date' => $date,
            'appointments' => $appointments->map(fn (Appointment $appointment): array => $this->serialize($appointment))->all(),
        ]]);
    }

    public function store(StorePublicAppointmentRequest $request, BookAppointment $booking): JsonResponse
    {
        $appointment = $booking->handle($request->validated(), $request->user());

        return response()->json(['data' => $this->serialize($appointment->load(['patient', 'serviceOffering.unit', 'serviceOffering.agreement', 'serviceOffering.professional', 'scheduleOccurrence']))], 201);
    }

    public function lookupPatient(Request $request): JsonResponse
    {
        $request->merge(['cpf' => preg_replace('/\D/', '', (string) $request->input('cpf'))]);
        $data = $request->validate(['cpf' => ['required', 'digits:11']]);
        $hash = hash_hmac('sha256', $data['cpf'], (string) config('sistema_dez.identity_pepper'));
        $patient = Patient::query()->where('cpf_hash', $hash)->first();

        return response()->json(['data' => $patient?->only(['id', 'name', 'birth_date', 'phone', 'email', 'cpf_last_four'])]);
    }

    public function reschedule(Request $request, Appointment $appointment, ChangeAppointment $change): JsonResponse
    {
        $appointment = $change->reschedule($request, $appointment);

        return response()->json(['data' => $this->serialize($appointment->load(['patient', 'serviceOffering.procedure', 'serviceOffering.unit', 'serviceOffering.agreement', 'serviceOffering.professional', 'scheduleOccurrence']))]);
    }

    public function cancel(Request $request, Appointment $appointment, ChangeAppointment $change): JsonResponse
    {
        $appointment = $change->cancel($request, $appointment);

        return response()->json(['data' => $this->serialize($appointment->load(['patient', 'serviceOffering.procedure', 'serviceOffering.unit', 'serviceOffering.agreement', 'serviceOffering.professional', 'scheduleOccurrence']))]);
    }

    /** @return array<string, mixed> */
    private function serialize(Appointment $appointment): array
    {
        $offering = $appointment->serviceOffering;
        $procedure = $offering->procedure;
        $professional = $offering->professional;
        $duration = $offering->duration_override_minutes ?? $procedure->duration_minutes;
        $startsAt = $appointment->scheduled_at->setTimezone($offering->unit->timezone);
        $endsAt = $appointment->scheduleOccurrence ? $startsAt->setTimeFromTimeString($appointment->scheduleOccurrence->ends_at) : $startsAt->addMinutes((int) $duration);

        return [
            'id' => $appointment->id,
            'offering_id' => $offering->id,
            'scheduled_at' => $startsAt->toIso8601String(),
            'ends_at' => $endsAt->toIso8601String(),
            'status' => $appointment->status,
            'kind' => $appointment->kind,
            'origin' => $appointment->origin,
            'patient_name' => $appointment->patient?->name,
            'unit' => ['id' => $offering->unit->id, 'name' => $offering->unit->name],
            'agreement' => ['id' => $offering->agreement->id, 'name' => $offering->agreement->name, 'is_default' => $offering->agreement->is_default],
            'cancellation_reason' => $appointment->cancellation_reason,
            'professional' => $professional === null ? null : [
                'id' => $professional->id,
                'name' => $professional->name,
            ],
            'procedure' => [
                'id' => $procedure->id,
                'name' => $procedure->name,
                'group_name' => $procedure->group_name,
            ],
            'price' => $appointment->price_snapshot,
            'payment_status' => $appointment->payment_status,
            'paid_at' => $appointment->paid_at?->toIso8601String(),
            'payment_method_code' => $appointment->paymentMethod?->code,
            'receipt_number' => $appointment->cashMovement?->receipt_number,
            'care_started_at' => $appointment->care_started_at?->toIso8601String(),
        ];
    }
}
