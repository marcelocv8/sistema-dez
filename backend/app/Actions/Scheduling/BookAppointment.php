<?php

namespace App\Actions\Scheduling;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Professional;
use App\Models\ScheduleOccurrence;
use App\Models\ServiceOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookAppointment
{
    public function __construct(private readonly BuildAvailability $availability, private readonly MaterializeScheduleOccurrences $materialize) {}

    /** @param array<string, mixed> $data */
    public function handle(array $data, ?User $actor = null): Appointment
    {
        return DB::transaction(function () use ($data, $actor): Appointment {
            $startsAt = CarbonImmutable::parse($data['starts_at'])->setTimezone(config('sistema_dez.timezone'));
            $existing = Appointment::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $this->existingOrFail($existing, $data, $startsAt, $actor);
            }
            if ($actor && ($startsAt->isPast() || $startsAt->greaterThan(CarbonImmutable::today(config('sistema_dez.timezone'))->addDays(89)->endOfDay()))) {
                throw ValidationException::withMessages(['starts_at' => 'Escolha um horário futuro dentro dos próximos 90 dias.']);
            }

            $professionalId = ServiceOffering::query()->whereKey($data['offering_id'])->value('professional_id');
            if ($professionalId) {
                Professional::query()->whereKey($professionalId)->lockForUpdate()->firstOrFail();
            }
            $offering = ServiceOffering::query()->with(['procedure', 'unit', 'agreement', 'professional'])
                ->lockForUpdate()->findOrFail($data['offering_id']);
            if (! $actor && (! ServiceOffering::query()->whereKey($offering->id)->publiclyAvailable()->exists()
                || $startsAt->lessThan(now()->addMinutes(config('sistema_dez.public_booking_lead_minutes')))
                || $startsAt->greaterThan(CarbonImmutable::today($offering->unit->timezone)->addDays(config('sistema_dez.public_booking_horizon_days') - 1)->endOfDay())
                || $startsAt->toDateString() > $offering->published_until->toDateString())) {
                throw ValidationException::withMessages(['starts_at' => 'Oferta indisponível online ou horário fora da janela permitida (antecedência mínima de 30 minutos).']);
            }
            if ($actor && (! $offering->active || ! $offering->unit->operational_enabled || ! $offering->unit->active
                || ! $offering->procedure->active || ! $offering->agreement->active || ! $offering->professional?->active
                || ! $offering->catalogLink?->active)) {
                throw ValidationException::withMessages(['offering_id' => 'A oferta não está habilitada para a operação.']);
            }
            $existing = Appointment::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $this->existingOrFail($existing, $data, $startsAt, $actor);
            }

            $this->materialize->handle($offering, $startsAt->startOfDay(), $startsAt->startOfDay());
            $occurrence = ScheduleOccurrence::query()->where('service_offering_id', $offering->id)
                ->where('date', $startsAt->toDateString())->where('starts_at', $startsAt->format('H:i:s'))->lockForUpdate()->first();
            if (! $occurrence || $occurrence->status !== 'OPEN') {
                throw ValidationException::withMessages(['starts_at' => 'Este horário não está mais disponível.']);
            }
            $available = collect($this->availability->handle([
                'internal' => $actor !== null,
                'offering_id' => $offering->id,
                'procedure_id' => $offering->procedure_id,
                'unit_id' => $offering->unit_id,
                'agreement_id' => $offering->agreement_id,
                'modality' => $offering->modality->value,
                'professional_id' => $offering->professional_id,
                'from' => $startsAt->toDateString(),
                'to' => $startsAt->toDateString(),
            ]))->flatMap(fn (array $row) => $row['slots']);

            if (! $available->contains(fn (array $slot) => CarbonImmutable::parse($slot['starts_at'])->equalTo($startsAt))) {
                throw ValidationException::withMessages(['starts_at' => 'Este horário não está mais disponível.']);
            }

            $cpf = $data['patient']['cpf'];
            $cpfHash = hash_hmac('sha256', $cpf, (string) config('sistema_dez.identity_pepper'));
            $patient = Patient::query()->firstOrCreate(
                ['cpf_hash' => $cpfHash],
                [
                    'name' => $data['patient']['name'],
                    'cpf_last_four' => substr($cpf, -4),
                    'birth_date' => $data['patient']['birth_date'] ?? null,
                    'phone' => $data['patient']['phone'],
                    'email' => $data['patient']['email'] ?? null,
                    'privacy_consent_at' => now(),
                    'privacy_consent_source' => $actor ? 'RECEPTION' : 'PUBLIC_BOOKING',
                ],
            );
            $managementToken = hash_hmac('sha256', $data['idempotency_key'], (string) config('sistema_dez.management_token_secret'));
            $appointment = Appointment::query()->create([
                'patient_id' => $patient->id,
                'service_offering_id' => $offering->id,
                'schedule_occurrence_id' => $occurrence->id,
                'scheduled_at' => $startsAt->utc(),
                'recommended_arrival_at' => $startsAt->utc(),
                'status' => 'CONFIRMED',
                'kind' => 'NEW',
                'origin' => $actor ? 'RECEPTION' : 'ONLINE',
                'created_by' => $actor?->id,
                'price_snapshot' => $offering->price,
                'idempotency_key' => $data['idempotency_key'],
                'management_token_hash' => hash('sha256', $managementToken),
            ]);
            AuditLog::query()->create([
                'entity_type' => Appointment::class,
                'entity_id' => $appointment->id,
                'action' => 'appointment.created',
                'actor_type' => $actor ? 'user' : 'public_patient',
                'actor_id' => $actor ? (string) $actor->id : $patient->id,
                'after' => ['status' => 'CONFIRMED', 'offering_id' => $offering->id, 'scheduled_at' => $startsAt->toIso8601String()],
                'metadata' => ['origin' => $actor ? 'RECEPTION' : 'ONLINE'],
            ]);

            $appointment->forceFill(['management_expires_at' => $actor ? null : $startsAt->addDay()->utc()])->save();

            return $this->withManagementToken($appointment->load('serviceOffering.procedure'), $data['idempotency_key']);
        }, 3);
    }

    private function withManagementToken(Appointment $appointment, string $idempotencyKey): Appointment
    {
        $appointment->setAttribute('management_token', hash_hmac('sha256', $idempotencyKey, (string) config('sistema_dez.management_token_secret')));

        return $appointment->loadMissing('serviceOffering.procedure');
    }

    /** @param array<string, mixed> $data */
    private function existingOrFail(Appointment $appointment, array $data, CarbonImmutable $startsAt, ?User $actor): Appointment
    {
        $cpfHash = hash_hmac('sha256', $data['patient']['cpf'], (string) config('sistema_dez.identity_pepper'));
        if ($appointment->service_offering_id !== $data['offering_id'] || ! $appointment->scheduled_at->equalTo($startsAt)
            || $appointment->patient?->cpf_hash !== $cpfHash || $appointment->origin !== ($actor ? 'RECEPTION' : 'ONLINE')) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Esta chave de idempotência já foi usada com outros dados.',
            ]);
        }

        return $this->withManagementToken($appointment, $data['idempotency_key']);
    }
}
