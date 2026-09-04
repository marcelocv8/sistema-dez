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

class CashClosingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_close_exact_by_cashier_without_supervisor(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T07:00:00-03:00'));
        $offering = $this->offeringWithWindow();
        $cashier = User::factory()->create(['role' => UserRole::Cashier]);
        $this->actingAs($cashier);
        $register = $this->postJson('/api/v1/team/cash-registers', [
            'unit_id' => $offering->unit_id, 'shift' => 'UNICO', 'float_amount' => '100.00',
        ])->assertCreated()->json('data');

        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $appointment = $this->book($offering);
        $this->actingAs($cashier);
        $this->postJson("/api/v1/team/appointments/{$appointment}/checkout", ['payment_method_code' => 'ESPECIE'])->assertCreated();

        // Esperado: 100 fundo + 150 espécie = 250.
        $this->postJson("/api/v1/team/cash-registers/{$register['id']}/close", ['counted_cash' => '250.00'])
            ->assertOk()->assertJsonPath('data.status', 'FECHADO')
            ->assertJsonPath('data.cash_difference', '0.00');
        $this->assertDatabaseHas('cash_registers', ['id' => $register['id'], 'status' => 'FECHADO', 'counted_cash' => '250.00']);
        $this->assertDatabaseHas('audit_logs', ['entity_key' => $register['id'], 'action' => 'cash.closed']);
    }

    public function test_shortage_requires_note_and_supervisor(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T07:00:00-03:00'));
        $offering = $this->offeringWithWindow();
        $cashier = User::factory()->create(['role' => UserRole::Cashier]);
        $this->actingAs($cashier);
        $register = $this->postJson('/api/v1/team/cash-registers', [
            'unit_id' => $offering->unit_id, 'shift' => 'UNICO', 'float_amount' => '100.00',
        ])->assertCreated()->json('data');

        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $appointment = $this->book($offering);
        $this->actingAs($cashier);
        $this->postJson("/api/v1/team/appointments/{$appointment}/checkout", ['payment_method_code' => 'ESPECIE'])->assertCreated();

        // Sem justificativa: 422.
        $this->postJson("/api/v1/team/cash-registers/{$register['id']}/close", ['counted_cash' => '240.00'])
            ->assertUnprocessable()->assertJsonValidationErrors('note');

        // Caixa com justificativa mas sem supervisor: 403.
        $this->postJson("/api/v1/team/cash-registers/{$register['id']}/close", ['counted_cash' => '240.00', 'note' => 'Faltaram R$ 10 no cofre'])
            ->assertForbidden();

        // Supervisor fecha com quebra.
        $this->actingAs(User::factory()->create(['role' => UserRole::Supervisor]));
        $this->postJson("/api/v1/team/cash-registers/{$register['id']}/close", ['counted_cash' => '240.00', 'note' => 'Faltaram R$ 10 no cofre'])
            ->assertOk()->assertJsonPath('data.status', 'FECHADO')
            ->assertJsonPath('data.cash_difference', '-10.00');
        $this->assertDatabaseHas('cash_registers', ['id' => $register['id'], 'cash_difference' => '-10.00']);
    }

    public function test_close_batch_is_partial_with_report(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T07:00:00-03:00'));
        $offering = $this->offeringWithWindow();
        $cashier = User::factory()->create(['role' => UserRole::Cashier]);
        $this->actingAs($cashier);
        $first = $this->postJson('/api/v1/team/cash-registers', [
            'unit_id' => $offering->unit_id, 'shift' => 'MANHA', 'float_amount' => '50.00',
        ])->assertCreated()->json('data');
        $this->postJson("/api/v1/team/cash-registers/{$first['id']}/close", ['counted_cash' => '50.00'])->assertOk();

        // Abrir segundo após fechar o primeiro (só um ABERTO por unidade).
        $second = $this->postJson('/api/v1/team/cash-registers', [
            'unit_id' => $offering->unit_id, 'shift' => 'TARDE', 'float_amount' => '20.00',
        ])->assertCreated()->json('data');

        $response = $this->postJson('/api/v1/team/cash-registers/close-batch', ['items' => [
            ['id' => $first['id'], 'counted_cash' => '50.00'],
            ['id' => $second['id'], 'counted_cash' => '20.00'],
        ]])->assertOk()->json('data');

        $this->assertCount(1, $response['closed']);
        $this->assertSame($second['id'], $response['closed'][0]['id']);
        $this->assertCount(1, $response['failed']);
        $this->assertSame($first['id'], $response['failed'][0]['id']);
        $this->assertSame(422, $response['failed'][0]['code']);
    }

    public function test_sangria_reduces_expected_cash_and_is_audited(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T07:00:00-03:00'));
        $offering = $this->offeringWithWindow();
        $cashier = User::factory()->create(['role' => UserRole::Cashier]);
        $this->actingAs($cashier);
        $register = $this->postJson('/api/v1/team/cash-registers', [
            'unit_id' => $offering->unit_id, 'shift' => 'UNICO', 'float_amount' => '100.00',
        ])->assertCreated()->json('data');

        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $appointment = $this->book($offering);
        $this->actingAs($cashier);
        $this->postJson("/api/v1/team/appointments/{$appointment}/checkout", ['payment_method_code' => 'ESPECIE'])->assertCreated();

        $this->postJson("/api/v1/team/cash-registers/{$register['id']}/sangrias", [
            'amount' => '30.00', 'description' => 'Pagamento fornecedor', 'proof_reference' => 'COMP-001',
        ])->assertCreated()->assertJsonPath('data.receipt_number', 2);
        $this->assertDatabaseHas('cash_movements', ['cash_register_id' => $register['id'], 'type' => 'SANGRIA', 'receipt_number' => 2]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'cash.sangria_created']);

        $summary = $this->getJson("/api/v1/team/cash-registers/current?unit_id={$offering->unit_id}")->assertOk()->json('data.summary');
        $this->assertSame('220.00', $summary['expected_cash']);
        $this->assertSame('30.00', $summary['sangria_total']);

        // Sangria em caixa fechado é rejeitada.
        $this->actingAs(User::factory()->create(['role' => UserRole::Supervisor]));
        $this->postJson("/api/v1/team/cash-registers/{$register['id']}/close", ['counted_cash' => '220.00'])->assertOk();
        $this->actingAs($cashier);
        $this->postJson("/api/v1/team/cash-registers/{$register['id']}/sangrias", ['amount' => '10.00', 'description' => 'Fora de hora'])
            ->assertUnprocessable();
    }

    public function test_void_payment_requires_supervisor_and_unblocks_cancel(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T07:00:00-03:00'));
        $offering = $this->offeringWithWindow();
        $cashier = User::factory()->create(['role' => UserRole::Cashier]);
        $this->actingAs($cashier);
        $this->postJson('/api/v1/team/cash-registers', [
            'unit_id' => $offering->unit_id, 'shift' => 'UNICO', 'float_amount' => '0.00',
        ])->assertCreated();

        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $appointment = $this->book($offering);
        $this->actingAs($cashier);
        $this->postJson("/api/v1/team/appointments/{$appointment}/checkout", ['payment_method_code' => 'PIX'])->assertCreated();

        // Caixa não estorna (sem discounts.validate).
        $this->postJson("/api/v1/team/appointments/{$appointment}/void-payment", ['reason' => 'Cobrança em duplicidade'])->assertForbidden();

        // Cancelar ficha paga sem estorno é bloqueado.
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $this->patchJson("/api/v1/team/appointments/{$appointment}/cancel", ['reason' => 'Paciente desistiu'])
            ->assertUnprocessable()->assertJsonValidationErrors('payment');

        // Supervisor estorna e a ficha volta a PENDING.
        $this->actingAs(User::factory()->create(['role' => UserRole::Supervisor]));
        $this->postJson("/api/v1/team/appointments/{$appointment}/void-payment", ['reason' => 'Cobrança em duplicidade'])
            ->assertCreated()->assertJsonPath('data.type', 'ESTORNO');
        $this->assertDatabaseHas('appointments', ['id' => $appointment, 'payment_status' => 'PENDING']);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $appointment, 'action' => 'payment.voided']);

        // Agora o cancelamento passa.
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $this->patchJson("/api/v1/team/appointments/{$appointment}/cancel", ['reason' => 'Paciente desistiu'])->assertOk();
    }

    public function test_index_lists_open_registers_with_filters(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T07:00:00-03:00'));
        $offering = $this->offeringWithWindow();
        $cashier = User::factory()->create(['role' => UserRole::Cashier]);
        $this->actingAs($cashier);
        $register = $this->postJson('/api/v1/team/cash-registers', [
            'unit_id' => $offering->unit_id, 'shift' => 'UNICO', 'float_amount' => '100.00',
        ])->assertCreated()->json('data');

        $this->getJson('/api/v1/team/cash-registers?status=ABERTO')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $register['id']);
        $this->getJson('/api/v1/team/cash-registers?status=FECHADO')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/team/cash-registers?unit_id={$offering->unit_id}&status=ABERTO&from=2026-09-04&to=2026-09-04")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_daily_report_totals_and_rbac(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T07:00:00-03:00'));
        $offering = $this->offeringWithWindow();
        $cashier = User::factory()->create(['role' => UserRole::Cashier]);
        $this->actingAs($cashier);
        $register = $this->postJson('/api/v1/team/cash-registers', [
            'unit_id' => $offering->unit_id, 'shift' => 'UNICO', 'float_amount' => '100.00',
        ])->assertCreated()->json('data');

        $this->getJson("/api/v1/team/cash-registers/daily-report?unit_id={$offering->unit_id}&date=2026-09-04")
            ->assertOk()->assertJsonPath('data.totals.expected_cash', '100.00');

        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $this->getJson("/api/v1/team/cash-registers/daily-report?unit_id={$offering->unit_id}&date=2026-09-04")->assertForbidden();
        $this->postJson('/api/v1/team/cash-registers/close-batch', ['items' => [['id' => $register['id'], 'counted_cash' => '100.00']]])->assertForbidden();
        $this->postJson("/api/v1/team/cash-registers/{$register['id']}/sangrias", ['amount' => '10.00', 'description' => 'Teste'])->assertForbidden();
    }

    private function offeringWithWindow(): ServiceOffering
    {
        $price = ProcedurePrice::factory()->create(['amount' => '150.00']);
        $price->unit->update(['operational_enabled' => true]);
        $price->agreement->update(['is_default' => true, 'requires_authorization' => false]);
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
