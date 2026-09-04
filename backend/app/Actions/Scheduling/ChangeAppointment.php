<?php

namespace App\Actions\Scheduling;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Professional;
use App\Models\ScheduleOccurrence;
use App\Models\ServiceOffering;
use App\Services\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangeAppointment
{
    public function __construct(private readonly AuditRecorder $audit, private readonly BuildAvailability $availability, private readonly MaterializeScheduleOccurrences $materialize) {}

    public function reschedule(Request $request, Appointment $appointment, bool $public = false): Appointment
    {
        $availability = $this->availability;
        $materialize = $this->materialize;
        $data = $request->validate(['offering_id' => ['required', 'uuid'], 'starts_at' => ['required', 'date', 'after:now'], 'reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $appointment = DB::transaction(function () use ($request, $appointment, $data, $availability, $materialize, $public): Appointment {
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointment->id);
            if ($public && ($appointment->scheduled_at->isPast() || $appointment->service_offering_id !== $data['offering_id'])) {
                throw ValidationException::withMessages(['offering_id' => 'A remarcação online mantém o mesmo serviço e só é permitida antes do atendimento.']);
            }
            $professionalIds = ServiceOffering::query()->whereIn('id', [$appointment->service_offering_id, $data['offering_id']])->pluck('professional_id')->filter();
            Professional::query()->whereIn('id', $professionalIds)->orderBy('id')->lockForUpdate()->get();
            $offerings = ServiceOffering::query()->whereIn('id', [$appointment->service_offering_id, $data['offering_id']])->orderBy('id')->lockForUpdate()->get();
            $offering = $offerings->firstWhere('id', $data['offering_id']);
            abort_unless($offering, 404);
            if (! $offering->active || ! $offering->unit->active || ! $offering->unit->operational_enabled || ! $offering->catalogLink?->active
                || ! $offering->procedure->active || ! $offering->agreement->active || ! $offering->professional?->active) {
                throw ValidationException::withMessages(['offering_id' => 'A oferta não está habilitada para a operação.']);
            }
            $startsAt = CarbonImmutable::parse($data['starts_at'])->setTimezone($offering->unit->timezone);
            if ($startsAt->greaterThan(CarbonImmutable::today($offering->unit->timezone)->addDays(89)->endOfDay())) {
                throw ValidationException::withMessages(['starts_at' => 'Escolha um horário dentro dos próximos 90 dias.']);
            }
            if ($public && (! ServiceOffering::query()->whereKey($offering->id)->publiclyAvailable()->exists()
                || $startsAt->lessThan(now()->addMinutes(config('sistema_dez.public_booking_lead_minutes')))
                || $startsAt->greaterThan(CarbonImmutable::today($offering->unit->timezone)->addDays(config('sistema_dez.public_booking_horizon_days') - 1)->endOfDay()))) {
                throw ValidationException::withMessages(['starts_at' => 'Escolha uma oferta visível no site e um horário com antecedência mínima de 30 minutos nos próximos 30 dias.']);
            }
            $materialize->handle($offering, $startsAt->startOfDay(), $startsAt->startOfDay());
            $occurrence = ScheduleOccurrence::query()->where('service_offering_id', $offering->id)->where('date', $startsAt->toDateString())
                ->where('starts_at', $startsAt->format('H:i:s'))->lockForUpdate()->first();
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointment->id);
            if ($appointment->status !== 'CONFIRMED') {
                throw ValidationException::withMessages(['status' => 'Somente agendamentos confirmados podem ser remarcados.']);
            }
            $rows = $availability->handle(['internal' => ! $public, 'offering_id' => $offering->id, 'exclude_appointment_id' => $appointment->id,
                'procedure_id' => $offering->procedure_id, 'unit_id' => $offering->unit_id, 'agreement_id' => $offering->agreement_id,
                'professional_id' => $offering->professional_id, 'modality' => $offering->modality->value,
                'from' => $startsAt->toDateString(), 'to' => $startsAt->toDateString()]);
            if (! $occurrence || $occurrence->status !== 'OPEN' || ! collect($rows)->flatMap(fn ($row) => $row['slots'])->contains(fn ($slot) => CarbonImmutable::parse($slot['starts_at'])->equalTo($startsAt))) {
                throw ValidationException::withMessages(['starts_at' => 'Este horário não está mais disponível.']);
            }
            $before = $appointment->attributesToArray();
            $appointment->update(['service_offering_id' => $offering->id, 'schedule_occurrence_id' => $occurrence->id,
                'scheduled_at' => $startsAt->utc(), 'recommended_arrival_at' => $startsAt->utc(), 'price_snapshot' => $offering->price]);
            if ($appointment->origin === 'ONLINE') {
                $appointment->forceFill(['management_expires_at' => $startsAt->addDay()->utc()])->save();
            }
            $this->record($request, $appointment, 'appointment.rescheduled', $before, [...$appointment->attributesToArray(), 'reason' => $data['reason']], $public);

            return $appointment;
        }, 3);

        return $appointment;
    }

    public function cancel(Request $request, Appointment $appointment, bool $public = false): Appointment
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $appointment = DB::transaction(function () use ($request, $appointment, $data, $public): Appointment {
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointment->id);
            if ($appointment->status === 'CANCELLED') {
                return $appointment;
            }
            if ($appointment->status !== 'CONFIRMED' || ($public && $appointment->scheduled_at->isPast())) {
                throw ValidationException::withMessages(['status' => 'Somente agendamentos futuros e confirmados podem ser cancelados pela agenda.']);
            }
            if ($appointment->payment_status === 'PAID') {
                throw ValidationException::withMessages(['payment' => 'Ficha paga exige estorno no caixa (supervisor) antes do cancelamento.']);
            }
            $before = $appointment->attributesToArray();
            $appointment->update(['status' => 'CANCELLED', 'cancellation_reason' => $data['reason']]);
            $this->record($request, $appointment, 'appointment.cancelled', $before, $appointment->attributesToArray(), $public);

            return $appointment;
        });

        return $appointment;
    }

    private function record(Request $request, Appointment $appointment, string $action, array $before, array $after, bool $public): void
    {
        if (! $public) {
            $this->audit->record($request, $appointment, $action, $before, $after);

            return;
        }
        AuditLog::create([
            'entity_type' => Appointment::class, 'entity_id' => $appointment->id, 'entity_key' => $appointment->id,
            'action' => $action, 'actor_type' => 'public_patient', 'actor_id' => $appointment->patient_id,
            'before' => $before, 'after' => $after, 'ip_address' => $request->ip(),
            'metadata' => ['route' => $request->route()?->getName(), 'origin' => 'PATIENT_SELF_SERVICE'],
        ]);
    }
}
