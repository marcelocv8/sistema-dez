<?php

namespace Tests\Feature;

use App\Enums\AsoStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\MtController;
use App\Models\Agreement;
use App\Models\Appointment;
use App\Models\B2bInvoice;
use App\Models\CashMovement;
use App\Models\LoyaltyDependent;
use App\Models\LoyaltyDue;
use App\Models\LoyaltyPlan;
use App\Models\LoyaltySubscription;
use App\Models\MtAso;
use App\Models\MtFunction;
use App\Models\MtSector;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\Professional;
use App\Models\Report;
use App\Models\ReportTemplate;
use App\Models\Unit;
use App\Models\User;
use App\Services\LoyaltyEligibility;
use App\Services\Stg\StgMtImporter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RemainingBlocksRegressionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function admin(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->travelTo(CarbonImmutable::parse('2026-09-09T10:00:00-03:00'));
    }

    private function collectDue(LoyaltyDue $due): void
    {
        $unit = Unit::factory()->create(['operational_enabled' => true]);
        $this->postJson('/api/v1/team/cash-registers', ['unit_id' => $unit->id, 'shift' => 'UNICO', 'float_amount' => '0.00'])->assertCreated();
        $this->postJson('/api/v1/team/loyalty/dues/'.$due->id.'/collect', ['unit_id' => $unit->id, 'payment_method_code' => 'PIX'])->assertCreated();
    }

    public function test_inactive_plan_denies_benefits(): void
    {
        $this->admin();
        $plan = LoyaltyPlan::factory()->create(['active' => false]);
        $patient = Patient::factory()->create();
        $this->getJson('/api/v1/team/loyalty/eligibility?agreement_id='.$plan->agreement_id.'&patient_id='.$patient->id)
            ->assertOk()->assertJsonPath('data.eligible', false)->assertJsonPath('data.plan.id', $plan->id);
    }

    public function test_old_payment_keeps_next_overdue_competence_blocked(): void
    {
        $this->admin();
        $plan = LoyaltyPlan::factory()->create(['due_day' => 10]);
        $sub = LoyaltySubscription::factory()->create(['plan_id' => $plan->id]);
        $due = LoyaltyDue::factory()->create(['subscription_id' => $sub->id, 'competence' => '2026-06-01', 'due_date' => '2026-06-10']);
        $this->collectDue($due);
        $this->assertDatabaseHas('loyalty_dues', ['subscription_id' => $sub->id, 'competence' => '2026-07-01', 'due_date' => '2026-07-10', 'status' => 'PENDENTE']);
        $this->assertFalse(app(LoyaltyEligibility::class)->check($plan, $sub->patient)['eligible']);
    }

    public function test_canceled_subscription_payment_does_not_generate_future_debt(): void
    {
        $this->admin();
        $sub = LoyaltySubscription::factory()->create(['status' => 'CANCELED', 'ended_at' => '2026-08-01']);
        $due = LoyaltyDue::factory()->create(['subscription_id' => $sub->id, 'competence' => '2026-07-01', 'due_date' => '2026-07-10']);
        $this->collectDue($due);
        $this->assertDatabaseMissing('loyalty_dues', ['subscription_id' => $sub->id, 'competence' => '2026-08-01', 'status' => 'PENDENTE']);
    }

    public function test_reactivation_rejects_conflicting_dependent(): void
    {
        $this->admin();
        $old = LoyaltySubscription::factory()->create(['status' => 'CANCELED']);
        $person = Patient::factory()->create();
        LoyaltyDependent::factory()->create(['subscription_id' => $old->id, 'patient_id' => $person->id, 'active' => true]);
        $current = LoyaltySubscription::factory()->create();
        LoyaltyDependent::factory()->create(['subscription_id' => $current->id, 'patient_id' => $person->id, 'active' => true]);
        $this->patchJson('/api/v1/team/loyalty/subscriptions/'.$old->id, ['status' => 'ACTIVE'])->assertUnprocessable();
        $this->assertSame(1, LoyaltyDependent::where('patient_id', $person->id)->whereHas('subscription', fn ($q) => $q->where('status', 'ACTIVE'))->count());
    }

    public function test_cancellation_preserves_arrears_and_reactivation_restores_current_billing(): void
    {
        $this->admin();
        $sub = LoyaltySubscription::factory()->create();
        $old = LoyaltyDue::factory()->create(['subscription_id' => $sub->id, 'competence' => '2026-08-01', 'due_date' => '2026-08-10']);
        $future = LoyaltyDue::factory()->create(['subscription_id' => $sub->id, 'competence' => '2026-10-01', 'due_date' => '2026-10-10']);
        $this->patchJson('/api/v1/team/loyalty/subscriptions/'.$sub->id, ['status' => 'CANCELED'])->assertOk();
        $this->assertSame('PENDENTE', $old->fresh()->status);
        $this->assertSame('CANCELADA', $future->fresh()->status);
        $this->travelTo(CarbonImmutable::parse('2026-10-09T10:00:00-03:00'));
        $this->patchJson('/api/v1/team/loyalty/subscriptions/'.$sub->id, ['status' => 'ACTIVE'])->assertOk();
        $this->assertSame('PENDENTE', $future->fresh()->status);
        $this->assertSame(1, LoyaltyDue::where('subscription_id', $sub->id)->whereDate('competence', '2026-10-01')->count());
        $this->assertFalse(app(LoyaltyEligibility::class)->check($sub->plan, $sub->patient)['eligible']);
    }

    public function test_migration_repairs_missing_due_reference_idempotently(): void
    {
        $this->admin();
        $due = LoyaltyDue::factory()->create();
        $this->collectDue($due);
        $movement = CashMovement::findOrFail($due->fresh()->cash_movement_id);
        $movement->forceFill(['loyalty_due_id' => null])->save();
        $migration = require database_path('migrations/2026_09_09_161439_backfill_loyalty_cash_movement_references.php');
        $migration->up();
        $migration->up();
        $this->assertSame($due->id, $movement->fresh()->loyalty_due_id);
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_aso_rejects_incompatible_references(): void
    {
        $this->admin();
        $patient = Patient::factory()->create();
        $company = Agreement::factory()->create();
        $sector = MtSector::factory()->create();
        $function = MtFunction::factory()->create();
        $appointment = Appointment::factory()->create();
        $base = ['patient_id' => $patient->id, 'agreement_id' => $company->id, 'type' => 'PERIODICO'];
        foreach ([['sector_id' => $sector->id], ['function_id' => $function->id], ['appointment_id' => $appointment->id]] as $invalid) {
            $this->postJson('/api/v1/team/mt/asos', $base + $invalid)->assertUnprocessable();
        }
        $appointment->forceFill(['patient_id' => $patient->id, 'status' => 'CANCELLED'])->save();
        $this->postJson('/api/v1/team/mt/asos', $base + ['appointment_id' => $appointment->id])->assertUnprocessable();
        $this->assertDatabaseCount('mt_asos', 0);
        $appointment->forceFill(['status' => 'CONFIRMED'])->save();
        $asoId = $this->postJson('/api/v1/team/mt/asos', $base + ['appointment_id' => $appointment->id])->assertCreated()->json('data.id');
        $this->putJson('/api/v1/team/mt/asos/'.$asoId, ['patient_id' => Patient::factory()->create()->id, 'type' => 'PERIODICO'])->assertUnprocessable();
        $this->assertSame($patient->id, MtAso::findOrFail($asoId)->patient_id);
    }

    public function test_emitted_aso_cannot_switch_patient(): void
    {
        $this->admin();
        $appointment = Appointment::factory()->create();
        $aso = MtAso::factory()->create(['patient_id' => $appointment->patient_id, 'appointment_id' => $appointment->id]);
        $reportId = $this->postJson('/api/v1/team/mt/asos/'.$aso->id.'/emit', [])->assertCreated()->json('data.report.id');
        $other = Patient::factory()->create();
        $this->putJson('/api/v1/team/mt/asos/'.$aso->id, ['patient_id' => $other->id, 'type' => 'PERIODICO'])->assertUnprocessable();
        $this->assertSame($appointment->patient_id, Report::findOrFail($reportId)->patient_id);
    }

    public function test_aso_print_includes_emission_expiry(): void
    {
        $this->admin();
        $appointment = Appointment::factory()->create();
        $aso = MtAso::factory()->create(['patient_id' => $appointment->patient_id, 'appointment_id' => $appointment->id, 'expires_at' => null]);
        $result = $this->postJson('/api/v1/team/mt/asos/'.$aso->id.'/emit', ['expires_at' => '2027-09-09'])->assertCreated()->json('data');
        $this->assertSame('2027-09-09', $result['aso']['expires_at']);
        $this->assertStringContainsString('Válido até:</strong> 09/09/2027', $result['report']['content_html']);
    }

    public function test_aging_respects_calendar_day_boundaries(): void
    {
        $this->admin();
        foreach ([0, 30, 31, 60, 61, 90, 91] as $days) {
            B2bInvoice::create([
                'agreement_id' => Agreement::factory()->create()->id,
                'competence' => '2026-09-01', 'status' => 'ENVIADA',
                'subtotal_gross' => '10.00', 'settled_amount' => '0.00',
                'due_at' => CarbonImmutable::today(config('sistema_dez.timezone'))->subDays($days)->toDateString(),
                'issued_at' => now(), 'created_by' => auth()->id(),
            ]);
        }
        $this->getJson('/api/v1/team/analytics')->assertOk()
            ->assertJsonPath('data.b2b_open.aging.a_vencer', 10)
            ->assertJsonPath('data.b2b_open.aging.vencida_30', 10)
            ->assertJsonPath('data.b2b_open.aging.vencida_60', 20)
            ->assertJsonPath('data.b2b_open.aging.vencida_90', 20)
            ->assertJsonPath('data.b2b_open.aging.vencida_90_mais', 10);
    }

    public function test_aging_places_old_invoice_in_over_90_days(): void
    {
        $this->admin();
        B2bInvoice::create(['agreement_id' => Agreement::factory()->create()->id, 'competence' => '2026-05-01', 'status' => 'ENVIADA', 'subtotal_gross' => '500.00', 'settled_amount' => '0.00', 'due_at' => '2026-05-01', 'issued_at' => now(), 'created_by' => auth()->id()]);
        $this->getJson('/api/v1/team/analytics')->assertOk()->assertJsonPath('data.b2b_open.aging.vencida_30', 0)->assertJsonPath('data.b2b_open.aging.vencida_90_mais', 500);
    }

    public function test_cancelled_asos_are_excluded_from_analytics_and_hermes(): void
    {
        $this->admin();
        $aso = MtAso::factory()->create(['status' => AsoStatus::Cancelado, 'expires_at' => '2026-09-08']);
        $this->getJson('/api/v1/team/analytics')->assertOk()->assertJsonPath('data.asos_due', 0);
        $token = $this->postJson('/api/v1/team/agents', ['name' => 'Review synthetic', 'scopes' => ['mt.read']])->assertCreated()->json('data.token');
        $this->withToken($token)->getJson('/api/v1/agents/asos/due')->assertOk()->assertJsonCount(0, 'data.asos');
    }

    public function test_revenue_export_respects_company_filter(): void
    {
        $this->admin();
        $a = Appointment::factory()->create(['payment_status' => 'PAID', 'paid_at' => now(), 'price_snapshot' => '123.00']);
        $other = Agreement::factory()->create();
        $this->getJson('/api/v1/team/analytics?agreement_id='.$other->id)->assertOk()->assertJsonPath('data.revenue', '0.00');
        $csv = $this->getJson('/api/v1/team/analytics/export?type=revenue&agreement_id='.$other->id)->assertOk()->streamedContent();
        $this->assertStringNotContainsString('123.00', $csv);
    }

    public function test_explicit_business_day_includes_late_evening_payment(): void
    {
        $this->admin();
        Appointment::factory()->create(['payment_status' => 'PAID', 'paid_at' => '2026-09-10T01:00:00Z', 'price_snapshot' => '150.00']);
        $this->getJson('/api/v1/team/analytics?from=2026-09-09&to=2026-09-09')->assertOk()->assertJsonPath('data.revenue', '150.00');
    }

    public function test_reimport_preserves_locally_cancelled_aso(): void
    {
        $this->admin();
        Agreement::factory()->create(['legacy_id' => 49]);
        foreach (range(1001, 1006) as $id) {
            Patient::factory()->create(['legacy_id' => $id]);
        }
        $importer = app(StgMtImporter::class);
        $path = base_path('tests/Fixtures/stg-mt');
        $importer->import($path, true, auth()->id());
        $aso = MtAso::where('legacy_id', 100)->firstOrFail();
        $this->postJson('/api/v1/team/mt/asos/'.$aso->id.'/cancel', ['reason' => 'Canceled in review'])->assertOk();
        $importer->import($path, true, auth()->id());
        $this->assertSame(AsoStatus::Cancelado, $aso->fresh()->status);
    }

    public function test_stale_emit_request_cannot_resurrect_cancelled_aso(): void
    {
        $this->admin();
        $appointment = Appointment::factory()->create();
        $aso = MtAso::factory()->create(['patient_id' => $appointment->patient_id, 'appointment_id' => $appointment->id]);
        $this->postJson('/api/v1/team/mt/asos/'.$aso->id.'/cancel', ['reason' => 'Concurrent cancel'])->assertOk();
        $request = Request::create('/api/v1/team/mt/asos/'.$aso->id.'/emit', 'POST');
        $request->setUserResolver(fn () => auth()->user());
        try {
            app(MtController::class)->asosEmit($request, $aso);
            $this->fail('Canceled ASO must not emit');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $this->assertSame(AsoStatus::Cancelado, $aso->fresh()->status);
        $this->assertDatabaseCount('reports', 0);
    }

    public function test_aso_rejects_incompatible_template(): void
    {
        $this->admin();
        $appointment = Appointment::factory()->create();
        $aso = MtAso::factory()->create(['patient_id' => $appointment->patient_id, 'appointment_id' => $appointment->id]);
        $template = ReportTemplate::factory()->create(['doctor_id' => Professional::factory()->create()->id, 'procedure_id' => Procedure::factory()->create()->id]);
        $this->postJson('/api/v1/team/mt/asos/'.$aso->id.'/emit', ['template_id' => $template->id])->assertUnprocessable();
    }

    public function test_cash_summary_includes_collected_monthly_fee(): void
    {
        $this->admin();
        $due = LoyaltyDue::factory()->create(['amount' => '89.90']);
        $this->collectDue($due);
        $this->assertDatabaseHas('cash_movements', ['type' => 'MENSALIDADE', 'gross_amount' => '89.90']);
        $token = $this->postJson('/api/v1/team/agents', ['name' => 'Review cash', 'scopes' => ['finance.read']])->assertCreated()->json('data.token');
        $this->withToken($token)->getJson('/api/v1/agents/cash-summary?date=2026-09-09')->assertOk()->assertJsonPath('data.revenue', '89.90');
    }

    public function test_hermes_controls_and_audit_for_validation_errors(): void
    {
        $this->admin();
        $token = $this->postJson('/api/v1/team/agents', ['name' => 'Review auth', 'scopes' => ['agenda.read']])->assertCreated()->json('data.token');
        $this->withToken($token)->getJson('/api/v1/agents/patients?cpf=11144477735')->assertForbidden();
        $this->assertDatabaseHas('agent_requests', ['status_code' => 403]);
        $this->withToken($token)->getJson('/api/v1/agents/agenda?from=invalid')->assertUnprocessable();
        $this->assertDatabaseHas('agent_requests', ['status_code' => 422]);
    }

    public function test_unit_filter_uses_cash_register_relation(): void
    {
        $this->admin();
        $unit = Unit::factory()->create();
        $this->getJson('/api/v1/team/analytics?unit_id='.$unit->id)->assertOk()->assertJsonPath('data.revenue', '0.00');
    }

    public function test_cash_movement_preserves_due_reference(): void
    {
        $this->admin();
        $due = LoyaltyDue::factory()->create();
        $this->collectDue($due);
        $this->assertNotNull($due->fresh()->cash_movement_id);
        $this->assertSame($due->id, CashMovement::findOrFail($due->fresh()->cash_movement_id)->loyalty_due_id);
    }

    public function test_hermes_rate_limit_is_enforced(): void
    {
        $this->admin();
        $token = $this->postJson('/api/v1/team/agents', ['name' => 'Review limit', 'scopes' => ['patients.read']])->assertCreated()->json('data.token');
        for ($i = 0; $i < 60; $i++) {
            $this->withToken($token)->getJson('/api/v1/agents/patients?cpf=00000000000')->assertOk();
        }
        $this->withToken($token)->getJson('/api/v1/agents/patients?cpf=00000000000')->assertStatus(429);
    }
}
