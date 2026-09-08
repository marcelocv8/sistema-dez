<?php

namespace Tests\Feature;

use App\Actions\Scheduling\MaterializeScheduleOccurrences;
use App\Enums\UserRole;
use App\Models\Agreement;
use App\Models\Appointment;
use App\Models\B2bInvoice;
use App\Models\Budget;
use App\Models\CatalogLink;
use App\Models\Patient;
use App\Models\ProcedurePrice;
use App\Models\Professional;
use App\Models\RoutineTask;
use App\Models\ServiceOffering;
use App\Models\User;
use App\Services\RoutineGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RoutineTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_generator_creates_confirmations_budget_returns_and_anniversaries_idempotently(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-07T07:00:00-03:00'));
        $creator = User::factory()->create(['role' => UserRole::Reception]);
        $patient = Patient::factory()->create(['name' => 'Maria Rotina']);
        $this->createConfirmedAppointment($patient, '2026-09-08T08:00:00-03:00');

        // Orçamento enviado ontem sem resposta.
        $budget = Budget::query()->create([
            'patient_id' => $patient->id, 'status' => 'ENVIADO', 'subtotal_gross' => '150.00',
            'sent_at' => CarbonImmutable::parse('2026-09-05T10:00:00-03:00'), 'created_by' => $creator->id,
        ]);

        // Atendimento de ~6 meses atrás (aniversariante) — direto pelo factory, sem API.
        $past = Appointment::factory()->create([
            'patient_id' => $patient->id, 'scheduled_at' => '2026-03-09T12:00:00Z', 'status' => 'DONE',
        ]);
        $past->serviceOffering->unit->update(['operational_enabled' => true]);

        // Fatura vencida em aberto.
        $invoice = B2bInvoice::query()->create([
            'agreement_id' => Agreement::factory()->create()->id,
            'competence' => '2026-08-01', 'status' => 'ENVIADA', 'subtotal_gross' => '300.00',
            'settled_amount' => '0.00', 'issued_at' => CarbonImmutable::parse('2026-08-01T10:00:00-03:00'),
            'due_at' => '2026-09-04', 'created_by' => $creator->id,
        ]);

        $created = app(RoutineGenerator::class)->generate(CarbonImmutable::parse('2026-09-07'));
        $this->assertSame(4, $created);

        $types = RoutineTask::query()->pluck('type', 'title');
        $this->assertTrue($types->contains(RoutineTask::CONFIRMACAO));
        $this->assertTrue($types->contains(RoutineTask::ORCAMENTO_RETORNO));
        $this->assertTrue($types->contains(RoutineTask::ANIVERSARIANTE));
        $this->assertTrue($types->contains(RoutineTask::FATURA_VENCENDO));
        $this->assertDatabaseHas('routine_tasks', ['type' => 'ORCAMENTO_RETORNO', 'ref_id' => $budget->id]);
        $this->assertDatabaseHas('routine_tasks', ['type' => 'FATURA_VENCENDO', 'ref_id' => $invoice->id]);

        // Idempotente: segunda geração não duplica.
        $again = app(RoutineGenerator::class)->generate(CarbonImmutable::parse('2026-09-07'));
        $this->assertSame(0, $again);
        $this->assertDatabaseCount('routine_tasks', 4);
    }

    public function test_tick_with_critique_reopen_and_rbac(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-07T07:00:00-03:00'));
        $patient = Patient::factory()->create();
        $this->createConfirmedAppointment($patient, '2026-09-08T08:00:00-03:00');
        app(RoutineGenerator::class)->generate(CarbonImmutable::parse('2026-09-07'));
        $task = RoutineTask::query()->where('type', 'CONFIRMACAO')->firstOrFail();

        $reception = User::factory()->create(['role' => UserRole::Reception]);
        $this->actingAs($reception);
        $list = $this->getJson('/api/v1/team/routines')->assertOk()->json('data.tasks');
        $this->assertCount(1, $list);

        // Tick exige... nada obrigatório, mas com crítica fica registrado.
        $this->postJson("/api/v1/team/routines/{$task->id}/tick", ['note' => 'Sem contato, tentar amanhã'])
            ->assertOk()->assertJsonPath('data.status', 'FEITO');
        $this->assertDatabaseHas('audit_logs', ['entity_key' => $task->id, 'action' => 'routine.ticked']);
        $this->assertDatabaseHas('routine_tasks', ['id' => $task->id, 'note' => 'Sem contato, tentar amanhã']);

        // Tick duplo bloqueado; reabrir só Admin/Supervisor com motivo.
        $this->postJson("/api/v1/team/routines/{$task->id}/tick")->assertUnprocessable();
        $this->actingAs(User::factory()->create(['role' => UserRole::Cashier]));
        $this->postJson("/api/v1/team/routines/{$task->id}/reopen", ['note' => 'Tentativa do caixa'])->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => UserRole::Supervisor]));
        $this->postJson("/api/v1/team/routines/{$task->id}/reopen", ['note' => 'Reaberta: paciente pediu para ligar depois'])
            ->assertOk()->assertJsonPath('data.status', 'PENDENTE');
        $this->assertDatabaseHas('audit_logs', ['entity_key' => $task->id, 'action' => 'routine.reopened']);

        // Recepção não gera rotinas (só Admin/Supervisor/Gerente).
        $this->actingAs($reception);
        $this->postJson('/api/v1/team/routines/generate')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->postJson('/api/v1/team/routines/generate')->assertCreated()->assertJsonPath('data.created', 0);

        // Supervisor também pode gerar sob demanda (routine.manage).
        $this->actingAs(User::factory()->create(['role' => UserRole::Supervisor]));
        $this->postJson('/api/v1/team/routines/generate')->assertCreated()->assertJsonPath('data.created', 0);
    }

    private function createConfirmedAppointment(Patient $patient, string $startsAt): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
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
        $offering->scheduleWindows()->create(['effective_from' => '2026-01-01', 'weekday' => [0, 1, 2, 3, 4, 5, 6][CarbonImmutable::parse($startsAt)->dayOfWeek],
            'starts_at' => '08:00:00', 'ends_at' => '18:00:00', 'slot_minutes' => 30, 'active' => true]);
        app(MaterializeScheduleOccurrences::class)->handle($offering);

        $this->postJson('/api/v1/team/appointments', [
            'idempotency_key' => Str::uuid(), 'offering_id' => $offering->id, 'starts_at' => $startsAt,
            'patient' => ['name' => $patient->name, 'cpf' => '11144477735', 'phone' => '85999999999', 'birth_date' => '1990-01-01'],
            'privacy_consent' => true,
        ])->assertCreated();
    }
}
