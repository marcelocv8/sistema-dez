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

class B2bInvoiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_generate_creates_monthly_invoice_and_is_idempotent(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T07:00:00-03:00'));
        $offering = $this->b2bOffering();
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $first = $this->book($offering, '2026-09-04T08:00:00-03:00', '11144477735');
        $second = $this->book($offering, '2026-09-04T08:30:00-03:00', '12345678909');
        $this->postJson("/api/v1/team/appointments/{$first}/authorize-b2b", ['authorization_code' => 'AUT-001'])->assertOk();
        $this->postJson("/api/v1/team/appointments/{$second}/authorize-b2b", ['authorization_code' => 'AUT-002'])->assertOk();

        $this->actingAs(User::factory()->create(['role' => UserRole::Manager]));
        $invoice = $this->postJson('/api/v1/team/b2b-invoices/generate', [
            'agreement_id' => $offering->agreement_id, 'competence' => '2026-09',
        ])->assertCreated()->json('data');

        $this->assertSame('EMITIDA', $invoice['status']);
        $this->assertSame('2026-09-01', $invoice['competence']);
        $this->assertSame('2026-09-14', $invoice['due_at']);
        $this->assertSame('300.00', $invoice['subtotal_gross']);
        $this->assertSame('300.00', $invoice['remaining_amount']);
        $this->assertFalse($invoice['overdue']);
        $this->assertCount(2, $invoice['items']);
        $this->assertDatabaseHas('audit_logs', ['entity_key' => $invoice['id'], 'action' => 'b2b.generated']);

        // Gerar de novo não duplica: mesma fatura, mesmos itens.
        $repeat = $this->postJson('/api/v1/team/b2b-invoices/generate', [
            'agreement_id' => $offering->agreement_id, 'competence' => '2026-09',
        ])->assertCreated()->json('data');
        $this->assertSame($invoice['id'], $repeat['id']);
        $this->assertCount(2, $repeat['items']);
        $this->assertDatabaseCount('b2b_invoice_items', 2);
    }

    public function test_send_and_partial_settle_until_paid(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T07:00:00-03:00'));
        $offering = $this->b2bOffering();
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $appointment = $this->book($offering, '2026-09-04T08:00:00-03:00', '11144477735');
        $this->postJson("/api/v1/team/appointments/{$appointment}/authorize-b2b", ['authorization_code' => 'AUT-001'])->assertOk();

        $this->actingAs(User::factory()->create(['role' => UserRole::Manager]));
        $invoice = $this->postJson('/api/v1/team/b2b-invoices/generate', [
            'agreement_id' => $offering->agreement_id, 'competence' => '2026-09',
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/team/b2b-invoices/{$invoice['id']}/send")
            ->assertOk()->assertJsonPath('data.status', 'ENVIADA');
        $this->assertDatabaseHas('audit_logs', ['entity_key' => $invoice['id'], 'action' => 'b2b.sent']);

        $this->actingAs(User::factory()->create(['role' => UserRole::Cashier]));
        $partial = $this->postJson("/api/v1/team/b2b-invoices/{$invoice['id']}/settle", [
            'amount' => '100.00', 'proof_reference' => 'TED-123',
        ])->assertOk()->json('data');
        $this->assertSame('ENVIADA', $partial['status']);
        $this->assertSame('100.00', $partial['settled_amount']);
        $this->assertSame('50.00', $partial['remaining_amount']);

        // Acima do restante, rejeita.
        $this->postJson("/api/v1/team/b2b-invoices/{$invoice['id']}/settle", [
            'amount' => '60.00', 'proof_reference' => 'TED-124',
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');

        $paid = $this->postJson("/api/v1/team/b2b-invoices/{$invoice['id']}/settle", [
            'amount' => '50.00', 'proof_reference' => 'TED-125',
        ])->assertOk()->json('data');
        $this->assertSame('PAGA', $paid['status']);
        $this->assertNotNull($paid['settled_at']);
        $this->assertDatabaseHas('audit_logs', ['entity_key' => $invoice['id'], 'action' => 'b2b.settled']);
    }

    public function test_overdue_invoice_blocks_new_b2b_authorization_until_settled(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T07:00:00-03:00'));
        $offering = $this->b2bOffering();
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $first = $this->book($offering, '2026-09-04T08:00:00-03:00', '11144477735');
        $this->postJson("/api/v1/team/appointments/{$first}/authorize-b2b", ['authorization_code' => 'AUT-001'])->assertOk();

        $this->actingAs(User::factory()->create(['role' => UserRole::Manager]));
        $invoice = $this->postJson('/api/v1/team/b2b-invoices/generate', [
            'agreement_id' => $offering->agreement_id, 'competence' => '2026-09',
        ])->assertCreated()->json('data');
        $this->postJson("/api/v1/team/b2b-invoices/{$invoice['id']}/send")->assertOk();

        // Vencimento em 14/09: em 18/09 a autorização nova trava (hard block).
        $this->travelTo(CarbonImmutable::parse('2026-09-18T07:00:00-03:00'));
        app(MaterializeScheduleOccurrences::class)->handle($offering);
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $second = $this->book($offering, '2026-09-18T08:00:00-03:00', '12345678909');
        $this->postJson("/api/v1/team/appointments/{$second}/authorize-b2b", ['authorization_code' => 'AUT-002'])
            ->assertUnprocessable()->assertJsonValidationErrors('appointment');

        $this->actingAs(User::factory()->create(['role' => UserRole::Cashier]));
        $this->postJson("/api/v1/team/b2b-invoices/{$invoice['id']}/settle", [
            'amount' => '150.00', 'proof_reference' => 'BOLETO-999',
        ])->assertOk()->assertJsonPath('data.status', 'PAGA');

        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $this->postJson("/api/v1/team/appointments/{$second}/authorize-b2b", ['authorization_code' => 'AUT-002'])
            ->assertOk()->assertJsonPath('data.payment_status', 'AUTHORIZED');
    }

    public function test_generate_rejects_default_agreement_and_rbac_is_enforced(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T07:00:00-03:00'));
        $offering = $this->b2bOffering(defaultAgreement: true);

        $this->actingAs(User::factory()->create(['role' => UserRole::Manager]));
        $this->postJson('/api/v1/team/b2b-invoices/generate', [
            'agreement_id' => $offering->agreement_id, 'competence' => '2026-09',
        ])->assertUnprocessable()->assertJsonValidationErrors('agreement_id');

        $b2b = $this->b2bOffering();
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $this->postJson('/api/v1/team/b2b-invoices/generate', [
            'agreement_id' => $b2b->agreement_id, 'competence' => '2026-09',
        ])->assertForbidden();
        $this->getJson('/api/v1/team/b2b-invoices')->assertOk();

        $appointment = $this->book($b2b, '2026-09-04T08:00:00-03:00', '12345678909');
        $this->postJson("/api/v1/team/appointments/{$appointment}/authorize-b2b", ['authorization_code' => 'AUT-009'])->assertOk();
        $this->actingAs(User::factory()->create(['role' => UserRole::Manager]));
        $invoice = $this->postJson('/api/v1/team/b2b-invoices/generate', [
            'agreement_id' => $b2b->agreement_id, 'competence' => '2026-09',
        ])->assertCreated()->json('data');

        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $this->postJson("/api/v1/team/b2b-invoices/{$invoice['id']}/settle", ['amount' => '10.00', 'proof_reference' => 'X-1'])->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => UserRole::Doctor]));
        $this->getJson('/api/v1/team/b2b-invoices')->assertForbidden();
    }

    public function test_cancel_is_blocked_while_invoiced_and_allowed_after_settle(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T07:00:00-03:00'));
        $offering = $this->b2bOffering();
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $appointment = $this->book($offering, '2026-09-04T08:00:00-03:00', '11144477735');
        $this->postJson("/api/v1/team/appointments/{$appointment}/authorize-b2b", ['authorization_code' => 'AUT-001'])->assertOk();

        $this->actingAs(User::factory()->create(['role' => UserRole::Manager]));
        $invoice = $this->postJson('/api/v1/team/b2b-invoices/generate', [
            'agreement_id' => $offering->agreement_id, 'competence' => '2026-09',
        ])->assertCreated()->json('data');

        // Fatura em aberto: cancelamento bloqueado com orientação ao módulo B2B.
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $this->patchJson("/api/v1/team/appointments/{$appointment}/cancel", ['reason' => 'Paciente desistiu'])
            ->assertUnprocessable()->assertJsonValidationErrors('payment');

        $this->actingAs(User::factory()->create(['role' => UserRole::Cashier]));
        $this->postJson("/api/v1/team/b2b-invoices/{$invoice['id']}/settle", [
            'amount' => '150.00', 'proof_reference' => 'TED-001',
        ])->assertOk()->assertJsonPath('data.status', 'PAGA');

        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $this->patchJson("/api/v1/team/appointments/{$appointment}/cancel", ['reason' => 'Paciente desistiu'])->assertOk();
        $this->assertDatabaseHas('appointments', ['id' => $appointment, 'status' => 'CANCELLED']);
    }

    private function b2bOffering(bool $defaultAgreement = false): ServiceOffering
    {
        $price = ProcedurePrice::factory()->create(['amount' => '150.00']);
        $price->unit->update(['operational_enabled' => true]);
        $price->agreement->update(['is_default' => $defaultAgreement, 'requires_authorization' => false, 'active' => true]);
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

    private function book(ServiceOffering $offering, string $startsAt, string $cpf): string
    {
        return $this->postJson('/api/v1/team/appointments', [
            'idempotency_key' => (string) Str::uuid(), 'offering_id' => $offering->id, 'starts_at' => $startsAt,
            'patient' => ['name' => 'Paciente Empresa', 'cpf' => $cpf, 'phone' => '85999999999', 'birth_date' => '1990-01-01'],
            'privacy_consent' => true,
        ])->assertCreated()->json('data.id');
    }
}
