<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Budget;
use App\Models\Patient;
use App\Models\ProcedurePrice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BudgetTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_reception_creates_sends_and_resolves_budget_obligatorily(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-07T07:00:00-03:00'));
        $price = ProcedurePrice::factory()->create(['amount' => '150.00']);
        $patient = Patient::factory()->create();
        $reception = User::factory()->create(['role' => UserRole::Reception]);
        $this->actingAs($reception);

        $budget = $this->postJson('/api/v1/team/budgets', [
            'patient_id' => $patient->id,
            'agreement_id' => $price->agreement_id, 'unit_id' => $price->unit_id,
            'items' => [
                ['procedure_price_id' => $price->id, 'procedure_id' => $price->procedure_id, 'amount' => '150.00', 'quantity' => 2],
            ],
            'prescription' => UploadedFile::fake()->createWithContent('prescricao.pdf', '%PDF-1.4 teste'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');

        $this->assertSame('RASCUNHO', $budget['status']);
        $this->assertSame('300.00', $budget['subtotal_gross']);
        $this->assertTrue($budget['has_prescription']);
        $this->assertDatabaseHas('audit_logs', ['entity_key' => $budget['id'], 'action' => 'budget.created']);
        $this->assertDatabaseHas('budgets', ['id' => $budget['id'], 'status' => 'RASCUNHO']);

        // Download da prescrição fica restrito a quem vê orçamentos.
        $download = $this->getJson("/api/v1/team/budgets/{$budget['id']}/prescription")->assertOk();
        $this->assertStringContainsString('attachment', (string) $download->headers->get('content-disposition'));
        Storage::disk('budgets')->assertExists(Budget::find($budget['id'])->prescription_path);
        $this->actingAs(User::factory()->create(['role' => UserRole::Doctor]));
        $this->getJson("/api/v1/team/budgets/{$budget['id']}/prescription")->assertForbidden();

        // Sem envio não resolve; enviado sem resposta não some: vira rotina D+1.
        $this->actingAs($reception);
        $this->postJson("/api/v1/team/budgets/{$budget['id']}/resolve", ['outcome' => 'ACEITO', 'note' => 'Aceito por ligação'])
            ->assertUnprocessable();
        $this->postJson("/api/v1/team/budgets/{$budget['id']}/send")->assertOk()->assertJsonPath('data.status', 'ENVIADO');
        $this->postJson("/api/v1/team/budgets/{$budget['id']}/send")->assertUnprocessable();

        // Resolução obrigatória: só aceita um dos três destinos.
        $this->postJson("/api/v1/team/budgets/{$budget['id']}/resolve", ['outcome' => 'ABERTO', 'note' => 'xx'])->assertUnprocessable();
        $resolved = $this->postJson("/api/v1/team/budgets/{$budget['id']}/resolve", ['outcome' => 'ACEITO', 'note' => 'Aceito por ligação'])
            ->assertOk()->json('data');
        $this->assertSame('ACEITO', $resolved['status']);
        $this->assertNotNull($resolved['resolved_at']);
        $this->assertDatabaseHas('audit_logs', ['entity_key' => $budget['id'], 'action' => 'budget.resolved']);
    }

    public function test_rbac_budgets_and_price_options(): void
    {
        $price = ProcedurePrice::factory()->create(['amount' => '80.00']);
        $this->actingAs(User::factory()->create(['role' => UserRole::Doctor]));
        $this->getJson('/api/v1/team/budgets')->assertForbidden();
        $this->getJson("/api/v1/team/budgets/price-options?agreement_id={$price->agreement_id}&unit_id={$price->unit_id}&q=exa")->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $options = $this->getJson('/api/v1/team/budgets/price-options?'.http_build_query(['agreement_id' => $price->agreement_id, 'unit_id' => $price->unit_id, 'q' => substr((string) $price->procedure->name, 0, 5)]))
            ->assertOk()->json('data');
        $this->assertNotEmpty($options);
        $this->assertSame('80.00', $options[0]['amount']);
        $this->assertSame($price->procedure_id, $options[0]['procedure_id']);

        $this->actingAs(User::factory()->create(['role' => UserRole::Cashier]));
        $this->postJson('/api/v1/team/budgets', ['patient_id' => Patient::factory()->create()->id, 'items' => [
            ['procedure_price_id' => $price->id, 'procedure_id' => $price->procedure_id, 'amount' => '80.00'],
        ]])->assertForbidden();
    }
}
