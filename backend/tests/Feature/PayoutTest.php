<?php

namespace Tests\Feature;

use App\Actions\Scheduling\MaterializeScheduleOccurrences;
use App\Enums\UserRole;
use App\Models\CatalogLink;
use App\Models\ProcedurePrice;
use App\Models\Professional;
use App\Models\ServiceOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PayoutTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_generate_uses_default_percent_and_includes_paid_and_authorized(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T07:00:00-03:00'));
        $professional = Professional::factory()->create(['commission_type' => 'PERCENT', 'commission_value' => '30.00']);
        $paid = $this->offeringFor($professional);
        $b2b = $this->offeringFor($professional, true);
        $cashier = User::factory()->create(['role' => UserRole::Cashier]);
        $this->actingAs($cashier);
        $this->postJson('/api/v1/team/cash-registers', [
            'unit_id' => $paid->unit_id, 'shift' => 'UNICO', 'float_amount' => '0.00',
        ])->assertCreated();

        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $first = $this->book($paid, '2026-09-04T08:00:00-03:00');
        $second = $this->book($b2b, '2026-09-04T08:30:00-03:00', '98765432100');
        $this->actingAs($cashier);
        $this->postJson("/api/v1/team/appointments/{$first}/checkout", ['payment_method_code' => 'ESPECIE'])->assertCreated();
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $this->postJson("/api/v1/team/appointments/{$second}/authorize-b2b", ['authorization_code' => 'AUT-001'])->assertOk();

        $this->actingAs(User::factory()->create(['role' => UserRole::Supervisor]));
        $payout = $this->postJson('/api/v1/team/payouts/generate', [
            'professional_id' => $professional->id, 'from' => '2026-09-04', 'to' => '2026-09-04',
        ])->assertCreated()->json('data');
        $this->assertSame('OPEN', $payout['status']);
        $this->assertSame('300.00', $payout['subtotal_gross']);
        $this->assertSame('90.00', $payout['total_commission']);
        $this->assertCount(2, $payout['items']);
        $this->assertDatabaseHas('audit_logs', ['entity_key' => $payout['id'], 'action' => 'payout.generated']);

        // Idempotente: nada novo no período.
        $this->postJson('/api/v1/team/payouts/generate', [
            'professional_id' => $professional->id, 'from' => '2026-09-04', 'to' => '2026-09-04',
        ])->assertUnprocessable();
    }

    public function test_override_per_procedure_wins_over_default(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T07:00:00-03:00'));
        $professional = Professional::factory()->create(['commission_type' => 'PERCENT', 'commission_value' => '10.00']);
        $offering = $this->offeringFor($professional);
        $cashier = User::factory()->create(['role' => UserRole::Cashier]);
        $this->actingAs($cashier);
        $this->postJson('/api/v1/team/cash-registers', [
            'unit_id' => $offering->unit_id, 'shift' => 'UNICO', 'float_amount' => '0.00',
        ])->assertCreated();
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $appointment = $this->book($offering);

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->postJson("/api/v1/team/professionals/{$professional->id}/commissions", [
            'procedure_id' => $offering->procedure_id, 'commission_type' => 'FIXED', 'commission_value' => '50.00',
        ])->assertCreated();
        $this->actingAs($cashier);
        $this->postJson("/api/v1/team/appointments/{$appointment}/checkout", ['payment_method_code' => 'ESPECIE'])->assertCreated();

        $this->actingAs(User::factory()->create(['role' => UserRole::Supervisor]));
        $payout = $this->postJson('/api/v1/team/payouts/generate', [
            'professional_id' => $professional->id, 'from' => '2026-09-04', 'to' => '2026-09-04',
        ])->assertCreated()->json('data');
        $this->assertSame('50.00', $payout['total_commission']);
        $this->assertSame('OVERRIDE_FIXED', $payout['items'][0]['rule']);
    }

    public function test_only_linked_doctor_confirms_and_supervisor_adjusts(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T07:00:00-03:00'));
        $professional = Professional::factory()->create(['commission_type' => 'PERCENT', 'commission_value' => '20.00']);
        $offering = $this->offeringFor($professional);
        $doctor = User::factory()->create(['role' => UserRole::Doctor]);
        $professional->forceFill(['user_id' => $doctor->id])->save();
        $other = User::factory()->create(['role' => UserRole::Doctor]);
        $cashier = User::factory()->create(['role' => UserRole::Cashier]);
        $this->actingAs($cashier);
        $this->postJson('/api/v1/team/cash-registers', [
            'unit_id' => $offering->unit_id, 'shift' => 'UNICO', 'float_amount' => '0.00',
        ])->assertCreated();
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $appointment = $this->book($offering);
        $this->actingAs($cashier);
        $this->postJson("/api/v1/team/appointments/{$appointment}/checkout", ['payment_method_code' => 'ESPECIE'])->assertCreated();

        $this->actingAs(User::factory()->create(['role' => UserRole::Supervisor]));
        $payout = $this->postJson('/api/v1/team/payouts/generate', [
            'professional_id' => $professional->id, 'from' => '2026-09-04', 'to' => '2026-09-04',
        ])->assertCreated()->json('data');

        // Caixa não confirma; outro médico não confirma o alheio.
        $this->actingAs($cashier);
        $this->postJson("/api/v1/team/payouts/{$payout['id']}/confirm")->assertForbidden();
        $this->actingAs($other);
        $this->postJson("/api/v1/team/payouts/{$payout['id']}/confirm")->assertForbidden();

        // Médico contesta, supervisor ajusta, médico confirma.
        $this->actingAs($doctor);
        $this->postJson("/api/v1/team/payouts/{$payout['id']}/contest", ['note' => 'Valor abaixo do combinado'])
            ->assertOk()->assertJsonPath('data.status', 'CONTESTED');
        $this->actingAs(User::factory()->create(['role' => UserRole::Supervisor]));
        $item = $payout['items'][0]['id'];
        $this->patchJson("/api/v1/team/payout-items/{$item}/commission", ['commission_amount' => '40.00'])->assertOk();
        $this->actingAs($doctor);
        $this->postJson("/api/v1/team/payouts/{$payout['id']}/confirm")
            ->assertOk()->assertJsonPath('data.status', 'CONFIRMED')
            ->assertJsonPath('data.total_commission', '40.00');
        $this->assertDatabaseHas('audit_logs', ['entity_key' => $payout['id'], 'action' => 'payout.confirmed']);
    }

    public function test_pay_cash_reduces_expected_and_pix_does_not(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T07:00:00-03:00'));
        $professional = Professional::factory()->create(['commission_type' => 'PERCENT', 'commission_value' => '20.00']);
        $offering = $this->offeringFor($professional);
        $doctor = User::factory()->create(['role' => UserRole::Doctor]);
        $professional->forceFill(['user_id' => $doctor->id])->save();
        $cashier = User::factory()->create(['role' => UserRole::Cashier]);
        $this->actingAs($cashier);
        $register = $this->postJson('/api/v1/team/cash-registers', [
            'unit_id' => $offering->unit_id, 'shift' => 'UNICO', 'float_amount' => '100.00',
        ])->assertCreated()->json('data');
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $first = $this->book($offering, '2026-09-04T08:00:00-03:00');
        $second = $this->book($offering, '2026-09-04T08:30:00-03:00', '98765432100');
        $this->actingAs($cashier);
        $this->postJson("/api/v1/team/appointments/{$first}/checkout", ['payment_method_code' => 'ESPECIE'])->assertCreated();
        $this->postJson("/api/v1/team/appointments/{$second}/checkout", ['payment_method_code' => 'ESPECIE'])->assertCreated();

        $this->actingAs(User::factory()->create(['role' => UserRole::Supervisor]));
        $payout = $this->postJson('/api/v1/team/payouts/generate', [
            'professional_id' => $professional->id, 'from' => '2026-09-04', 'to' => '2026-09-04',
        ])->assertCreated()->json('data');
        $this->assertSame('60.00', $payout['total_commission']);
        $this->actingAs($doctor);
        $this->postJson("/api/v1/team/payouts/{$payout['id']}/confirm")->assertOk();

        // PIX sem comprovante é rejeitado; com comprovante não mexe no caixa.
        $this->actingAs($cashier);
        $this->postJson("/api/v1/team/payouts/{$payout['id']}/pay", ['payment_method_code' => 'PIX'])
            ->assertUnprocessable()->assertJsonValidationErrors('proof_reference');
        $this->postJson("/api/v1/team/payouts/{$payout['id']}/pay", ['payment_method_code' => 'PIX', 'proof_reference' => 'PIX-123'])
            ->assertOk()->assertJsonPath('data.status', 'PAID');
        $this->assertDatabaseCount('cash_movements', 2);

        // Segundo repasse (só a terceira ficha: 20% de 150 = 30) pago em espécie abate o esperado: 100 + 450 − 30 = 520.
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $third = $this->book($offering, '2026-09-04T09:00:00-03:00', '11144477735');
        $this->actingAs($cashier);
        $this->postJson("/api/v1/team/appointments/{$third}/checkout", ['payment_method_code' => 'ESPECIE'])->assertCreated();
        $this->actingAs(User::factory()->create(['role' => UserRole::Supervisor]));
        $secondPayout = $this->postJson('/api/v1/team/payouts/generate', [
            'professional_id' => $professional->id, 'from' => '2026-09-04', 'to' => '2026-09-04',
        ])->assertCreated()->json('data');
        $this->actingAs($doctor);
        $this->postJson("/api/v1/team/payouts/{$secondPayout['id']}/confirm")->assertOk();
        $this->actingAs($cashier);
        $this->postJson("/api/v1/team/payouts/{$secondPayout['id']}/pay", [
            'payment_method_code' => 'ESPECIE', 'cash_register_id' => $register['id'],
        ])->assertOk()->assertJsonPath('data.status', 'PAID');
        $summary = $this->getJson("/api/v1/team/cash-registers/current?unit_id={$offering->unit_id}")->assertOk()->json('data.summary');
        $this->assertSame('520.00', $summary['expected_cash']);
        $this->assertSame('30.00', $summary['repasse_total']);
    }

    public function test_void_and_cancel_are_blocked_by_confirmed_payout(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T07:00:00-03:00'));
        $professional = Professional::factory()->create(['commission_type' => 'PERCENT', 'commission_value' => '20.00']);
        $offering = $this->offeringFor($professional);
        $doctor = User::factory()->create(['role' => UserRole::Doctor]);
        $professional->forceFill(['user_id' => $doctor->id])->save();
        $cashier = User::factory()->create(['role' => UserRole::Cashier]);
        $this->actingAs($cashier);
        $this->postJson('/api/v1/team/cash-registers', [
            'unit_id' => $offering->unit_id, 'shift' => 'UNICO', 'float_amount' => '0.00',
        ])->assertCreated();
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $appointment = $this->book($offering);
        $this->actingAs($cashier);
        $this->postJson("/api/v1/team/appointments/{$appointment}/checkout", ['payment_method_code' => 'ESPECIE'])->assertCreated();

        $this->actingAs(User::factory()->create(['role' => UserRole::Supervisor]));
        $payout = $this->postJson('/api/v1/team/payouts/generate', [
            'professional_id' => $professional->id, 'from' => '2026-09-04', 'to' => '2026-09-04',
        ])->assertCreated()->json('data');
        $this->actingAs($doctor);
        $this->postJson("/api/v1/team/payouts/{$payout['id']}/confirm")->assertOk();

        $this->actingAs(User::factory()->create(['role' => UserRole::Supervisor]));
        $this->postJson("/api/v1/team/appointments/{$appointment}/void-payment", ['reason' => 'Tentativa'])->assertUnprocessable();
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $this->patchJson("/api/v1/team/appointments/{$appointment}/cancel", ['reason' => 'Tentativa'])->assertUnprocessable();
    }

    public function test_my_professional_endpoint(): void
    {
        $doctor = User::factory()->create(['role' => UserRole::Doctor]);
        $this->actingAs($doctor);
        $this->getJson('/api/v1/team/professionals/mine')->assertNotFound();

        $professional = Professional::factory()->create(['user_id' => $doctor->id, 'commission_type' => 'FIXED', 'commission_value' => '80.00']);
        $this->getJson('/api/v1/team/professionals/mine')
            ->assertOk()->assertJsonPath('data.id', $professional->id)
            ->assertJsonPath('data.commission_value', '80.00');

        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $this->getJson('/api/v1/team/professionals/mine')->assertForbidden();
    }

    public function test_professional_commission_validation_and_rbac(): void
    {
        $professional = Professional::factory()->create();
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $this->postJson('/api/v1/team/payouts/generate', [
            'professional_id' => $professional->id, 'from' => '2026-09-04', 'to' => '2026-09-04',
        ])->assertForbidden();
        $this->getJson('/api/v1/team/payouts')->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->putJson("/api/v1/team/catalog/professionals/{$professional->id}", [
            'name' => $professional->name, 'active' => true, 'online_enabled' => false,
            'commission_type' => 'PERCENT', 'commission_value' => '150.00',
        ])->assertUnprocessable()->assertJsonValidationErrors('commission_value');

        $doctor = User::factory()->create(['role' => UserRole::Doctor]);
        $this->putJson("/api/v1/team/catalog/professionals/{$professional->id}", [
            'name' => $professional->name, 'active' => true, 'online_enabled' => false,
            'commission_type' => 'PERCENT', 'commission_value' => '25.00', 'user_id' => $doctor->id,
        ])->assertOk()->assertJsonPath('data.commission_value', '25.00');

        $this->actingAs($doctor);
        $this->getJson("/api/v1/team/payouts?professional_id={$professional->id}")->assertOk();
        // Médico gera o próprio (sem fichas: 422 de período, não 403).
        $this->postJson('/api/v1/team/payouts/generate', [
            'professional_id' => $professional->id, 'from' => '2026-09-04', 'to' => '2026-09-04',
        ])->assertUnprocessable()->assertJsonValidationErrors('period');
        // Médico não gera o alheio.
        $otherProfessional = Professional::factory()->create();
        $this->postJson('/api/v1/team/payouts/generate', [
            'professional_id' => $otherProfessional->id, 'from' => '2026-09-04', 'to' => '2026-09-04',
        ])->assertForbidden();
    }

    private function offeringFor(Professional $professional, bool $b2b = false): ServiceOffering
    {
        $price = ProcedurePrice::factory()->create(['amount' => '150.00']);
        $price->unit->update(['operational_enabled' => true]);
        $price->agreement->update(['is_default' => ! $b2b, 'requires_authorization' => false]);
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

    private function book(ServiceOffering $offering, string $startsAt = '2026-09-04T08:00:00-03:00', string $cpf = '12345678909'): string
    {
        return $this->postJson('/api/v1/team/appointments', [
            'idempotency_key' => (string) Str::uuid(), 'offering_id' => $offering->id, 'starts_at' => $startsAt,
            'patient' => ['name' => 'Paciente Repasse', 'cpf' => $cpf, 'phone' => '85999999999', 'birth_date' => '1990-01-01'],
            'privacy_consent' => true,
        ])->assertCreated()->json('data.id');
    }
}
