<?php

namespace Tests\Feature;

use App\Actions\Scheduling\MaterializeScheduleOccurrences;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\CatalogLink;
use App\Models\ProcedurePrice;
use App\Models\Professional;
use App\Models\ServiceOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashFoundationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_cashier_opens_register_and_charges_with_sequential_receipt_and_fee(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T07:00:00-03:00'));
        $offering = $this->offeringWithWindow();
        $cashier = User::factory()->create(['role' => UserRole::Cashier]);
        $this->actingAs($cashier);

        $register = $this->postJson('/api/v1/team/cash-registers', [
            'unit_id' => $offering->unit_id, 'shift' => 'UNICO', 'float_amount' => '100.00',
        ])->assertCreated()->json('data');
        $this->assertSame('ABERTO', $register['status']);

        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $appointment = $this->book($offering);
        $this->actingAs($cashier);
        $r1 = $this->postJson("/api/v1/team/appointments/{$appointment}/checkout", ['payment_method_code' => 'CREDITO']);
        $receipt = $r1->assertCreated()
            ->assertJsonPath('data.payment_status', 'PAID')
            ->assertJsonPath('data.receipt_number', 1)
            ->assertJsonPath('data.gross_amount', '150.00')
            ->json('data');
        $this->assertDatabaseHas('cash_movements', ['appointment_id' => $appointment, 'receipt_number' => 1, 'type' => 'RECEBIMENTO']);
        $this->assertDatabaseHas('appointments', ['id' => $appointment, 'payment_status' => 'PAID']);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $appointment, 'action' => 'payment.charged']);
        $this->assertDatabaseHas('queue_entries', ['appointment_id' => $appointment, 'status' => 'CHECKOUT']);

        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $second = $this->book($offering, '2026-09-04T08:30:00-03:00');
        $this->actingAs($cashier);
        $this->postJson("/api/v1/team/appointments/{$second}/checkout", ['payment_method_code' => 'ESPECIE'])
            ->assertCreated()->assertJsonPath('data.receipt_number', 2);

        $summary = $this->getJson("/api/v1/team/cash-registers/current?unit_id={$offering->unit_id}")
            ->assertOk()->json('data.summary');
        $this->assertSame(2, $summary['receipt_count']);
        $this->assertSame('250.00', $summary['expected_cash']);
    }

    public function test_double_charge_closed_register_and_inactive_method_are_rejected(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T07:00:00-03:00'));
        $offering = $this->offeringWithWindow();
        $this->actingAs(User::factory()->create(['role' => UserRole::Cashier]));
        $register = $this->postJson('/api/v1/team/cash-registers', [
            'unit_id' => $offering->unit_id, 'shift' => 'UNICO', 'float_amount' => '50.00',
        ])->assertCreated()->json('data');

        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $appointment = $this->book($offering);
        $other = $this->book($offering, '2026-09-04T08:30:00-03:00');
        $third = $this->book($offering, '2026-09-04T09:00:00-03:00');
        $this->actingAs(User::factory()->create(['role' => UserRole::Cashier]));
        $this->postJson("/api/v1/team/appointments/{$appointment}/checkout", ['payment_method_code' => 'PIX'])->assertCreated();
        $this->postJson("/api/v1/team/appointments/{$appointment}/checkout", ['payment_method_code' => 'PIX'])->assertUnprocessable();
        $this->assertDatabaseCount('cash_movements', 1);

        $this->postJson("/api/v1/team/appointments/{$other}/checkout", ['payment_method_code' => 'FIADO'])->assertUnprocessable();

        $this->postJson("/api/v1/team/cash-registers/{$register['id']}/close", ['counted_cash' => '50.00'])->assertOk()->assertJsonPath('data.status', 'FECHADO');
        $this->postJson("/api/v1/team/appointments/{$third}/checkout", ['payment_method_code' => 'PIX'])
            ->assertUnprocessable()->assertJsonValidationErrors('cash_register');
    }

    public function test_opening_second_register_for_same_unit_is_rejected(): void
    {
        $offering = $this->offeringWithWindow();
        $this->actingAs(User::factory()->create(['role' => UserRole::Cashier]));
        $payload = ['unit_id' => $offering->unit_id, 'shift' => 'MANHA', 'float_amount' => '100.00'];
        $this->postJson('/api/v1/team/cash-registers', $payload)->assertCreated();
        $this->postJson('/api/v1/team/cash-registers', [...$payload, 'shift' => 'TARDE'])
            ->assertUnprocessable()->assertJsonValidationErrors('unit_id');
    }

    public function test_visit_flow_check_in_then_paid_then_care(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T07:00:00-03:00'));
        $offering = $this->offeringWithWindow();
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $appointment = $this->book($offering);

        $this->postJson("/api/v1/team/appointments/{$appointment}/start-care")
            ->assertUnprocessable()->assertJsonValidationErrors('appointment');
        $this->postJson("/api/v1/team/appointments/{$appointment}/check-in")->assertOk()->assertJsonPath('data.status', 'CHECKED_IN');
        $this->postJson("/api/v1/team/appointments/{$appointment}/start-care")
            ->assertUnprocessable()->assertJsonValidationErrors('appointment');

        $this->actingAs(User::factory()->create(['role' => UserRole::Cashier]));
        $this->postJson('/api/v1/team/cash-registers', ['unit_id' => $offering->unit_id, 'shift' => 'UNICO', 'float_amount' => '0.00'])->assertCreated();
        $this->postJson("/api/v1/team/appointments/{$appointment}/checkout", ['payment_method_code' => 'DEBITO'])->assertCreated();

        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $this->postJson("/api/v1/team/appointments/{$appointment}/start-care")
            ->assertOk()->assertJsonPath('data.status', 'CHECKED_IN');
        $this->assertDatabaseHas('appointments', ['id' => $appointment]);
        $this->assertNotNull(Appointment::findOrFail($appointment)->care_started_at);
        $this->assertDatabaseHas('queue_entries', ['appointment_id' => $appointment, 'status' => 'IN_SERVICE']);
    }

    public function test_b2b_authorization_skips_cashier_and_allows_care(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T07:00:00-03:00'));
        $offering = $this->offeringWithWindow(b2b: true);
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $appointment = $this->book($offering);

        $this->postJson("/api/v1/team/appointments/{$appointment}/authorize-b2b", ['authorization_code' => 'AUT-2026-001'])
            ->assertOk()->assertJsonPath('data.payment_status', 'AUTHORIZED');
        $this->postJson("/api/v1/team/appointments/{$appointment}/check-in")->assertOk();
        $this->postJson("/api/v1/team/appointments/{$appointment}/start-care")->assertOk();
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_reception_cannot_operate_cash_and_cashier_cannot_manage_catalog(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T07:00:00-03:00'));
        $offering = $this->offeringWithWindow();
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $appointment = $this->book($offering);

        $this->postJson('/api/v1/team/cash-registers', ['unit_id' => $offering->unit_id, 'shift' => 'UNICO', 'float_amount' => '0.00'])->assertForbidden();
        $this->postJson("/api/v1/team/appointments/{$appointment}/checkout", ['payment_method_code' => 'PIX'])->assertForbidden();
        $this->getJson('/api/v1/team/cash-registers/current')->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => UserRole::Cashier]));
        $this->postJson('/api/v1/team/catalog/procedures', ['name' => 'X'])->assertForbidden();
        $this->getJson('/api/v1/team/appointments?date=2026-09-04')->assertOk();

        $this->actingAs(User::factory()->create(['role' => UserRole::Doctor]));
        $this->getJson('/api/v1/team/appointments?date=2026-09-04')->assertOk();
        $this->postJson('/api/v1/team/cash-registers', ['unit_id' => $offering->unit_id, 'shift' => 'UNICO', 'float_amount' => '0.00'])->assertForbidden();
    }

    private function offeringWithWindow(bool $b2b = false): ServiceOffering
    {
        $price = ProcedurePrice::factory()->create(['amount' => '150.00']);
        $price->unit->update(['operational_enabled' => true]);
        $price->agreement->update(['is_default' => ! $b2b, 'requires_authorization' => false]);
        $professional = Professional::factory()->create();
        $link = CatalogLink::create(['unit_id' => $price->unit_id, 'procedure_id' => $price->procedure_id,
            'agreement_id' => $price->agreement_id, 'professional_id' => $professional->id, 'active' => true]);
        $offering = ServiceOffering::factory()->create([
            'unit_id' => $link->unit_id, 'procedure_id' => $link->procedure_id, 'professional_id' => $link->professional_id,
            'agreement_id' => $link->agreement_id, 'catalog_link_id' => $link->id, 'procedure_price_id' => $price->id,
            'price' => '150.00', 'booking_mode' => 'FIXED_SLOT', 'modality' => 'PRESENTIAL',
            'duration_override_minutes' => 30, 'active' => true, 'online_enabled' => false,
        ]);
        $offering->scheduleWindows()->create(['effective_from' => '2026-09-01', 'weekday' => 5,
            'starts_at' => '08:00:00', 'ends_at' => '10:00:00', 'slot_minutes' => 30, 'active' => true]);
        app(MaterializeScheduleOccurrences::class)->handle($offering);

        return $offering;
    }

    private function book(ServiceOffering $offering, string $startsAt = '2026-09-04T08:00:00-03:00'): string
    {
        return $this->postJson('/api/v1/team/appointments', [
            'idempotency_key' => (string) Str::uuid(), 'offering_id' => $offering->id, 'starts_at' => $startsAt,
            'patient' => ['name' => 'Paciente Caixa', 'cpf' => '12345678909', 'phone' => '85999999999', 'birth_date' => '1990-01-01'],
            'privacy_consent' => true,
        ])->assertCreated()->json('data.id');
    }
}
