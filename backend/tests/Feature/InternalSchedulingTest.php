<?php

namespace Tests\Feature;

use App\Actions\Scheduling\MaterializeScheduleOccurrences;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\CatalogLink;
use App\Models\ProcedurePrice;
use App\Models\Professional;
use App\Models\ScheduleOccurrence;
use App\Models\ServiceOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InternalSchedulingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_creates_offline_offering_from_valid_link_and_price(): void
    {
        [$link, $price] = $this->catalog();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->postJson('/api/v1/team/service-offerings', $this->offeringPayload($link, $price))->assertCreated()
            ->assertJsonPath('data.online_enabled', false)->assertJsonPath('data.price', '150.00');

        $this->assertDatabaseHas('service_offerings', ['catalog_link_id' => $link->id, 'procedure_price_id' => $price->id, 'online_enabled' => false]);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => ServiceOffering::class, 'action' => 'created']);
    }

    public function test_reception_cannot_manage_offerings_and_online_input_is_rejected(): void
    {
        [$link, $price] = $this->catalog();
        $payload = $this->offeringPayload($link, $price);
        $this->actingAs(User::factory()->create())->postJson('/api/v1/team/service-offerings', $payload)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->postJson('/api/v1/team/service-offerings', [...$payload, 'online_enabled' => true])->assertUnprocessable()->assertJsonValidationErrors('online_enabled');
        $this->assertDatabaseCount('service_offerings', 0);
    }

    public function test_offering_rejects_inactive_link_quarantined_price_and_non_operational_unit(): void
    {
        [$link, $price] = $this->catalog();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $payload = $this->offeringPayload($link, $price);
        $link->update(['active' => false]);
        $this->postJson('/api/v1/team/service-offerings', $payload)->assertUnprocessable()->assertJsonValidationErrors('catalog_link_id');
        $link->update(['active' => true]);
        $price->update(['status' => 'QUARANTINED']);
        $this->postJson('/api/v1/team/service-offerings', $payload)->assertUnprocessable()->assertJsonValidationErrors('procedure_price_id');
        $price->update(['status' => 'VALID']);
        $link->unit->update(['operational_enabled' => false]);
        $this->postJson('/api/v1/team/service-offerings', $payload)->assertUnprocessable()->assertJsonValidationErrors('catalog_link_id');
        $this->assertDatabaseCount('service_offerings', 0);
    }

    public function test_scales_materialize_idempotently_reject_overlap_and_apply_exceptions(): void
    {
        $offering = $this->offering();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $url = '/api/v1/team/service-offerings/'.$offering->id;
        $window = $this->windowPayload();
        $this->postJson($url.'/schedule-windows', $window)->assertCreated();
        $this->postJson($url.'/schedule-windows', $window)->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        $count = ScheduleOccurrence::count();
        $this->assertGreaterThan(0, $count);
        app(MaterializeScheduleOccurrences::class)->handle($offering);
        $this->assertDatabaseCount('schedule_occurrences', $count);
        $this->postJson($url.'/schedule-exceptions', ['date' => '2026-09-07', 'starts_at' => '08:00', 'ends_at' => '08:30', 'reason' => 'Reunião', 'blocked' => true])->assertCreated();
        $this->assertDatabaseHas('schedule_occurrences', ['service_offering_id' => $offering->id, 'date' => '2026-09-07', 'starts_at' => '08:00:00', 'status' => 'CLOSED']);
        $this->assertDatabaseHas('schedule_occurrences', ['service_offering_id' => $offering->id, 'date' => '2026-09-07', 'starts_at' => '08:30:00', 'status' => 'OPEN']);
    }

    public function test_reception_books_remakes_and_cancels_without_publishing_or_losing_history(): void
    {
        $offering = $this->offeringWithWindow();
        $reception = User::factory()->create();
        $this->actingAs($reception);
        $payload = $this->bookingPayload($offering);
        $id = $this->postJson('/api/v1/team/appointments', $payload)->assertCreated()->assertJsonPath('data.origin', 'RECEPTION')->json('data.id');
        $this->postJson('/api/v1/team/appointments', $payload)->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('appointments', 1);
        $this->postJson('/api/v1/team/patients/lookup', ['cpf' => '123.456.789-09'])->assertOk()->assertJsonPath('data.name', 'Paciente Piloto');
        $this->getJson('/api/v1/team/appointments?date=2026-09-07&unit_id='.$offering->unit_id)->assertOk()->assertJsonCount(1, 'data.appointments');
        $offering->update(['price' => '175.00']);
        $this->patchJson('/api/v1/team/appointments/'.$id.'/reschedule', ['offering_id' => $offering->id, 'starts_at' => '2026-09-07T08:30:00-03:00', 'reason' => 'Pedido do paciente'])
            ->assertOk()->assertJsonPath('data.price', '175.00')->assertJsonPath('data.scheduled_at', '2026-09-07T08:30:00-03:00');
        $this->patchJson('/api/v1/team/appointments/'.$id.'/cancel', ['reason' => 'Paciente desistiu'])->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->assertDatabaseHas('appointments', ['id' => $id, 'created_by' => $reception->id, 'cancellation_reason' => 'Paciente desistiu']);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $id, 'action' => 'appointment.rescheduled']);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $id, 'action' => 'appointment.cancelled']);
        $this->assertDatabaseHas('service_offerings', ['id' => $offering->id, 'online_enabled' => false]);
    }

    public function test_last_slot_cannot_be_booked_twice_and_cancel_releases_capacity(): void
    {
        $offering = $this->offeringWithWindow();
        $this->actingAs(User::factory()->create());
        $first = $this->postJson('/api/v1/team/appointments', $this->bookingPayload($offering))->assertCreated()->json('data.id');
        $this->postJson('/api/v1/team/appointments', $this->bookingPayload($offering))->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        $this->patchJson('/api/v1/team/appointments/'.$first.'/cancel', ['reason' => 'Liberar vaga'])->assertOk();
        $this->postJson('/api/v1/team/appointments', $this->bookingPayload($offering))->assertCreated();
        $this->assertSame(1, Appointment::where('status', 'CONFIRMED')->count());
    }

    public function test_blocking_booked_occurrence_preserves_appointment_and_closes_new_capacity(): void
    {
        $offering = $this->offeringWithWindow();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $id = $this->postJson('/api/v1/team/appointments', $this->bookingPayload($offering))->assertCreated()->json('data.id');
        $occurrenceId = Appointment::findOrFail($id)->schedule_occurrence_id;
        $this->postJson('/api/v1/team/service-offerings/'.$offering->id.'/schedule-exceptions', ['date' => '2026-09-07', 'reason' => 'Médico ausente', 'blocked' => true])->assertCreated();
        $this->assertDatabaseHas('appointments', ['id' => $id, 'schedule_occurrence_id' => $occurrenceId]);
        $this->assertDatabaseHas('schedule_occurrences', ['id' => $occurrenceId, 'status' => 'CLOSED']);
    }

    private function catalog(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-02T07:00:00-03:00'));
        $price = ProcedurePrice::factory()->create(['amount' => '150.00']);
        $price->unit->update(['operational_enabled' => true]);
        $professional = Professional::factory()->create();
        $link = CatalogLink::create(['unit_id' => $price->unit_id, 'procedure_id' => $price->procedure_id, 'agreement_id' => $price->agreement_id, 'professional_id' => $professional->id, 'active' => true]);

        return [$link, $price];
    }

    private function offeringPayload(CatalogLink $link, ProcedurePrice $price): array
    {
        return ['catalog_link_id' => $link->id, 'procedure_price_id' => $price->id, 'booking_mode' => 'FIXED_SLOT', 'modality' => 'PRESENTIAL', 'duration_override_minutes' => 30, 'daily_capacity' => 10, 'arrival_block_minutes' => 60, 'arrival_block_capacity' => 3, 'active' => true];
    }

    private function offering(): ServiceOffering
    {
        [$link, $price] = $this->catalog();

        return ServiceOffering::factory()->create([...$this->offeringPayload($link, $price), 'unit_id' => $link->unit_id, 'procedure_id' => $link->procedure_id, 'professional_id' => $link->professional_id, 'agreement_id' => $link->agreement_id, 'price' => '150.00', 'online_enabled' => false]);
    }

    private function offeringWithWindow(): ServiceOffering
    {
        $offering = $this->offering();
        $offering->scheduleWindows()->create($this->windowPayload());
        app(MaterializeScheduleOccurrences::class)->handle($offering);

        return $offering;
    }

    private function windowPayload(): array
    {
        return ['effective_from' => '2026-09-01', 'effective_to' => null, 'weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '10:00', 'slot_minutes' => 30, 'daily_capacity_override' => null, 'active' => true];
    }

    private function bookingPayload(ServiceOffering $offering): array
    {
        return ['idempotency_key' => (string) Str::uuid(), 'offering_id' => $offering->id, 'starts_at' => '2026-09-07T08:00:00-03:00', 'patient' => ['name' => 'Paciente Piloto', 'cpf' => '123.456.789-09', 'phone' => '85999999999', 'birth_date' => '1990-01-01'], 'privacy_consent' => true];
    }
}
