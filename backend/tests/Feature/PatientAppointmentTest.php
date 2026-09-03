<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\ServiceOffering;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PatientAppointmentTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_patient_reads_reschedules_and_cancels_using_only_own_token(): void
    {
        [$offering, $payload] = $this->offering();
        $receipt = $this->postJson('/api/v1/public/appointments', $payload)->assertCreated()->json('data');
        $headers = ['Authorization' => 'Bearer '.$receipt['management_token']];
        $url = '/api/v1/public/appointments/'.$receipt['id'];
        $this->getJson($url, $headers)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonMissingPath('data.management_token')->assertJsonMissingPath('data.patient')->assertJsonPath('data.price', '150.00');
        $this->getJson($url.'/availability', $headers)->assertOk()->assertJsonPath('data.0.offering_id', $offering->id);
        $offering->update(['price' => '170.00']);
        $this->patchJson($url.'/reschedule', ['starts_at' => '2026-09-02T08:30:00-03:00', 'reason' => 'Outro horário'], $headers)
            ->assertOk()->assertJsonPath('data.scheduled_at', '2026-09-02T08:30:00-03:00')->assertJsonPath('data.price', '170.00');
        $this->patchJson($url.'/cancel', ['reason' => 'Não poderei comparecer'], $headers)->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->patchJson($url.'/cancel', ['reason' => 'Não poderei comparecer'], $headers)->assertOk();
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'appointment.rescheduled', 'actor_type' => 'public_patient']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'appointment.cancelled', 'actor_type' => 'public_patient']);
        $this->assertDatabaseCount('audit_logs', 3);
        $this->assertStringNotContainsString($receipt['management_token'], AuditLog::all()->toJson());
        $this->assertStringNotContainsString('management_token_hash', AuditLog::all()->toJson());
    }

    public function test_invalid_expired_or_other_appointment_token_returns_404(): void
    {
        [, $payload] = $this->offering();
        $receipt = $this->postJson('/api/v1/public/appointments', $payload)->assertCreated()->json('data');
        $url = '/api/v1/public/appointments/'.$receipt['id'];
        $this->getJson($url)->assertNotFound();
        $this->getJson($url, ['Authorization' => 'Bearer '.str_repeat('a', 64)])->assertNotFound();
        $this->getJson('/api/v1/public/appointments/'.Str::uuid(), ['Authorization' => 'Bearer '.$receipt['management_token']])->assertNotFound();
        $this->getJson($url.'?token='.$receipt['management_token'])->assertNotFound();
        $other = $this->postJson('/api/v1/public/appointments', [...$payload, 'idempotency_key' => (string) Str::uuid(), 'starts_at' => '2026-09-02T08:30:00-03:00'])->assertCreated()->json('data');
        $this->getJson('/api/v1/public/appointments/'.$other['id'], ['Authorization' => 'Bearer '.$receipt['management_token']])->assertNotFound();
        $this->patchJson('/api/v1/public/appointments/'.$other['id'].'/cancel', ['reason' => 'Tentativa cruzada'], ['Authorization' => 'Bearer '.$receipt['management_token']])->assertNotFound();
        $this->travelTo(CarbonImmutable::parse('2026-09-03T09:00:00-03:00'));
        $this->getJson($url, ['Authorization' => 'Bearer '.$receipt['management_token']])->assertNotFound();
    }

    public function test_patient_cannot_change_service_or_modify_started_appointment(): void
    {
        [$offering, $payload] = $this->offering();
        $receipt = $this->postJson('/api/v1/public/appointments', $payload)->assertCreated()->json('data');
        $headers = ['Authorization' => 'Bearer '.$receipt['management_token']];
        $url = '/api/v1/public/appointments/'.$receipt['id'];
        $this->patchJson($url.'/reschedule', ['offering_id' => $offering->id, 'starts_at' => '2026-09-02T08:30:00-03:00', 'reason' => 'Outro'], $headers)->assertUnprocessable();
        $this->travelTo(CarbonImmutable::parse('2026-09-02T08:01:00-03:00'));
        $this->patchJson($url.'/cancel', ['reason' => 'Outro'], $headers)->assertUnprocessable();
        $this->patchJson($url.'/reschedule', ['starts_at' => '2026-09-02T09:00:00-03:00', 'reason' => 'Outro'], $headers)->assertUnprocessable();
        $this->assertDatabaseHas('appointments', ['id' => $receipt['id'], 'status' => 'CONFIRMED']);
    }

    public function test_unpublication_blocks_new_booking_but_keeps_existing_cancellation(): void
    {
        [$offering, $payload] = $this->offering();
        $receipt = $this->postJson('/api/v1/public/appointments', $payload)->assertCreated()->json('data');
        $offering->update(['online_enabled' => false]);
        $headers = ['Authorization' => 'Bearer '.$receipt['management_token']];
        $url = '/api/v1/public/appointments/'.$receipt['id'];
        $this->patchJson($url.'/reschedule', ['starts_at' => '2026-09-02T08:30:00-03:00', 'reason' => 'Outro'], $headers)->assertUnprocessable();
        $payload['idempotency_key'] = (string) Str::uuid();
        $this->postJson('/api/v1/public/appointments', $payload)->assertUnprocessable();
        $this->patchJson($url.'/cancel', ['reason' => 'Cancelamento solicitado'], $headers)->assertOk();
    }

    public function test_public_booking_and_availability_enforce_lead_time_and_horizon(): void
    {
        [$offering, $payload] = $this->offering();
        $query = ['procedure_id' => $offering->procedure_id, 'unit_id' => $offering->unit_id, 'agreement_id' => $offering->agreement_id,
            'modality' => 'PRESENTIAL', 'from' => '2026-09-02', 'to' => '2026-09-02'];
        $offering->forceFill(['published_until' => '2026-09-02'])->save();
        $this->getJson('/api/v1/public/availability?'.http_build_query($query))->assertOk()->assertJsonPath('data.0.slots.0.starts_at', '2026-09-02T08:00:00-03:00');
        $this->travelTo(CarbonImmutable::parse('2026-09-02T07:31:00-03:00'));
        $this->postJson('/api/v1/public/appointments', $payload)->assertUnprocessable();
        $this->getJson('/api/v1/public/availability?'.http_build_query($query))->assertOk()->assertJsonPath('data.0.slots.0.starts_at', '2026-09-02T08:30:00-03:00');
        $query['from'] = $query['to'] = '2026-11-04';
        $this->getJson('/api/v1/public/availability?'.http_build_query($query))->assertUnprocessable();
        $payload['starts_at'] = '2026-11-04T08:00:00-03:00';
        $this->postJson('/api/v1/public/appointments', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_full_slot_reschedule_preserves_original_booking(): void
    {
        [, $payload] = $this->offering();
        $receipt = $this->postJson('/api/v1/public/appointments', $payload)->assertCreated()->json('data');
        $this->postJson('/api/v1/public/appointments', [...$payload, 'idempotency_key' => (string) Str::uuid(), 'starts_at' => '2026-09-02T08:30:00-03:00'])->assertCreated();
        $this->patchJson('/api/v1/public/appointments/'.$receipt['id'].'/reschedule',
            ['starts_at' => '2026-09-02T08:30:00-03:00', 'reason' => 'Outro horário'],
            ['Authorization' => 'Bearer '.$receipt['management_token']])->assertUnprocessable();
        $this->assertSame('2026-09-02T11:00:00+00:00', Appointment::findOrFail($receipt['id'])->scheduled_at->toIso8601String());
    }

    public function test_token_attempts_are_rate_limited(): void
    {
        $this->assertSame('array', config('cache.default'));
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.123']);
        $url = '/api/v1/public/appointments/'.Str::uuid();
        foreach (range(1, 30) as $attempt) {
            $this->getJson($url)->assertNotFound();
        }
        $this->getJson($url)->assertTooManyRequests();
    }

    private function offering(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-02T07:00:00-03:00'));
        $offering = ServiceOffering::factory()->published()->create(['price' => '150.00']);
        $offering->scheduleWindows()->create(['effective_from' => '2026-09-02', 'weekday' => 3, 'starts_at' => '08:00', 'ends_at' => '10:00', 'slot_minutes' => 30, 'active' => true]);

        return [$offering, ['idempotency_key' => (string) Str::uuid(), 'offering_id' => $offering->id, 'starts_at' => '2026-09-02T08:00:00-03:00',
            'patient' => ['name' => 'Paciente isolado', 'cpf' => '12345678909', 'phone' => '85999999999'], 'privacy_consent' => true]];
    }
}
