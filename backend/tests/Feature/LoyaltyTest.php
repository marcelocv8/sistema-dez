<?php

namespace Tests\Feature;

use App\Actions\Scheduling\MaterializeScheduleOccurrences;
use App\Enums\UserRole;
use App\Models\Agreement;
use App\Models\CashRegister;
use App\Models\CatalogLink;
use App\Models\LoyaltyDue;
use App\Models\LoyaltyPlan;
use App\Models\LoyaltySubscription;
use App\Models\Patient;
use App\Models\ProcedurePrice;
use App\Models\Professional;
use App\Models\ServiceOffering;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoyaltyTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_plan_requires_active_non_default_agreement_and_unique_link(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $default = Agreement::factory()->create(['is_default' => true]);
        $this->postJson('/api/v1/team/loyalty/plans', $this->planPayload($default))->assertUnprocessable()->assertJsonValidationErrors('agreement_id');
        $agreement = Agreement::factory()->create(['legacy_id' => '71', 'name' => 'SOU DEZ COMPLETO', 'type' => 'PLAN']);
        $id = $this->postJson('/api/v1/team/loyalty/plans', $this->planPayload($agreement))->assertCreated()->assertJsonPath('data.agreement.name', 'SOU DEZ COMPLETO')->json('data.id');
        $this->postJson('/api/v1/team/loyalty/plans', $this->planPayload($agreement))->assertUnprocessable()->assertJsonValidationErrors('agreement_id');
        $this->patchJson('/api/v1/team/loyalty/plans/'.$id, ['monthly_fee' => '99.90', 'due_day' => 5, 'active' => false])->assertOk()->assertJsonPath('data.monthly_fee', '99.90');
        $this->actingAs(User::factory()->create(['role' => UserRole::Doctor]))->getJson('/api/v1/team/loyalty/plans')->assertForbidden();
    }

    public function test_subscription_creates_titular_dependents_and_first_due(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-02T07:00:00-03:00'));
        $plan = LoyaltyPlan::factory()->create(['name' => 'SOU DEZ COMPLETO', 'monthly_fee' => '89.90', 'due_day' => 10]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $data = $this->postJson('/api/v1/team/loyalty/subscriptions', [
            'plan_id' => $plan->id,
            'patient' => ['cpf' => '123.456.789-09', 'name' => 'Titular Sou DEZ', 'birth_date' => '1990-01-01', 'phone' => '85999999999'],
            'dependents' => [['cpf' => '11144477735', 'name' => 'Dependente Sou DEZ']],
            'notes' => 'Assinatura na recepção',
        ])->assertCreated()->json('data');
        $this->assertSame('ACTIVE', $data['status']);
        $this->assertSame('2026-09-02', $data['started_at']);
        $this->assertCount(1, $data['dependents']);
        $this->assertCount(1, $data['dues']);
        $this->assertSame('PENDENTE', $data['dues'][0]['status']);
        $this->assertSame('2026-09-10', $data['dues'][0]['due_date']);
        $this->assertSame('89.90', $data['dues'][0]['amount']);
        $this->assertDatabaseHas('loyalty_dues', ['competence' => '2026-09-01', 'status' => 'PENDENTE']);
        $this->assertDatabaseHas('patients', ['name' => 'Dependente Sou DEZ']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'loyalty.subscription_created']);
        $this->postJson('/api/v1/team/loyalty/subscriptions', [
            'plan_id' => $plan->id,
            'patient' => ['cpf' => '12345678909', 'name' => 'Titular Sou DEZ'],
        ])->assertUnprocessable()->assertJsonValidationErrors('patient');
        $this->postJson('/api/v1/team/loyalty/subscriptions', [
            'plan_id' => $plan->id,
            'patient' => ['cpf' => '11144477735', 'name' => 'Dependente Sou DEZ'],
        ])->assertUnprocessable()->assertJsonValidationErrors('patient');
        $this->assertDatabaseCount('loyalty_subscriptions', 1);
    }

    public function test_booking_with_loyalty_agreement_requires_active_subscription(): void
    {
        $offering = $this->loyaltyOffering();
        $plan = LoyaltyPlan::query()->where('agreement_id', $offering->agreement_id)->firstOrFail();
        $this->actingAs(User::factory()->create());
        $this->postJson('/api/v1/team/appointments', $this->bookingPayload($offering))
            ->assertUnprocessable()->assertJsonValidationErrors('patient');
        $this->assertDatabaseCount('appointments', 0);
        $this->travelTo(CarbonImmutable::parse('2026-09-02T07:00:00-03:00'));
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $this->postJson('/api/v1/team/loyalty/subscriptions', [
            'plan_id' => $plan->id,
            'patient' => ['cpf' => '123.456.789-09', 'name' => 'Paciente Piloto', 'phone' => '85999999999'],
        ])->assertCreated();
        $this->actingAs(User::factory()->create());
        $this->travelTo(CarbonImmutable::parse('2026-09-02T08:00:00-03:00'));
        $this->postJson('/api/v1/team/appointments', $this->bookingPayload($offering))->assertCreated();
    }

    public function test_overdue_due_blocks_booking_and_checkout_until_paid_at_cash(): void
    {
        $offering = $this->loyaltyOffering();
        $plan = LoyaltyPlan::query()->where('agreement_id', $offering->agreement_id)->firstOrFail();
        $this->travelTo(CarbonImmutable::parse('2026-09-02T07:00:00-03:00'));
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $subscription = $this->postJson('/api/v1/team/loyalty/subscriptions', [
            'plan_id' => $plan->id,
            'patient' => ['cpf' => '123.456.789-09', 'name' => 'Paciente Piloto', 'phone' => '85999999999'],
        ])->assertCreated()->json('data');
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);
        $this->postJson('/api/v1/team/cash-registers', ['unit_id' => $offering->unit_id, 'shift' => 'UNICO', 'float_amount' => '100.00'])->assertCreated();
        $appointmentId = $this->postJson('/api/v1/team/appointments', $this->bookingPayload($offering))->assertCreated()->json('data.id');
        LoyaltyDue::factory()->overdue()->create(['subscription_id' => $subscription['id'], 'amount' => '89.90']);
        $this->postJson('/api/v1/team/appointments/'.$appointmentId.'/checkout', ['payment_method_code' => 'ESPECIE'])
            ->assertUnprocessable();
        $this->assertDatabaseHas('appointments', ['id' => $appointmentId, 'payment_status' => 'PENDING']);
        $overdue = LoyaltyDue::query()->where('subscription_id', $subscription['id'])->where('status', 'PENDENTE')
            ->where('due_date', '<', '2026-09-02')->firstOrFail();
        $this->postJson('/api/v1/team/loyalty/dues/'.$overdue->id.'/collect', ['payment_method_code' => 'ESPECIE', 'unit_id' => $offering->unit_id])->assertCreated();
        $this->assertDatabaseHas('loyalty_dues', ['id' => $overdue->id, 'status' => 'PAGA']);
        $this->postJson('/api/v1/team/appointments/'.$appointmentId.'/checkout', ['payment_method_code' => 'ESPECIE'])->assertCreated()
            ->assertJsonPath('data.payment_status', 'PAID');
    }

    public function test_collect_requires_open_register_generates_next_month_and_counts_in_cash(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-02T07:00:00-03:00'));
        $plan = LoyaltyPlan::factory()->create(['monthly_fee' => '89.90', 'due_day' => 10]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $subscription = $this->postJson('/api/v1/team/loyalty/subscriptions', [
            'plan_id' => $plan->id,
            'patient' => ['cpf' => '123.456.789-09', 'name' => 'Titular', 'phone' => '85999999999'],
        ])->assertCreated()->json('data');
        $dueId = $subscription['dues'][0]['id'];
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);
        $unit = Unit::factory()->create(['operational_enabled' => true]);
        $this->postJson('/api/v1/team/loyalty/dues/'.$dueId.'/collect', ['payment_method_code' => 'ESPECIE', 'unit_id' => $unit->id])
            ->assertUnprocessable()->assertJsonValidationErrors('cash_register');
        $this->postJson('/api/v1/team/cash-registers', ['unit_id' => $unit->id, 'shift' => 'UNICO', 'float_amount' => '50.00'])->assertCreated();
        $this->postJson('/api/v1/team/loyalty/dues/'.$dueId.'/collect', ['payment_method_code' => 'PIX', 'unit_id' => $unit->id])
            ->assertCreated()->assertJsonPath('data.status', 'PAGA');
        $this->assertDatabaseHas('cash_movements', ['type' => 'MENSALIDADE', 'receipt_number' => 1, 'gross_amount' => '89.90', 'net_amount' => '89.90']);
        $this->assertDatabaseHas('loyalty_dues', ['subscription_id' => $subscription['id'], 'competence' => '2026-10-01', 'status' => 'PENDENTE']);
        $this->postJson('/api/v1/team/loyalty/dues/'.$dueId.'/collect', ['payment_method_code' => 'ESPECIE', 'unit_id' => $unit->id])->assertUnprocessable()->assertJsonValidationErrors('due');
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_collect_forbidden_without_cash_operate(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-02T07:00:00-03:00'));
        $plan = LoyaltyPlan::factory()->create();
        $due = LoyaltyDue::factory()->create(['subscription_id' => LoyaltySubscription::factory()->create(['plan_id' => $plan->id])->id]);
        $unit = Unit::factory()->create(['operational_enabled' => true]);
        CashRegister::factory()->create(['unit_id' => $unit->id, 'opened_by' => User::factory()->create(['role' => UserRole::Admin])]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $this->postJson('/api/v1/team/loyalty/dues/'.$due->id.'/collect', ['payment_method_code' => 'ESPECIE', 'unit_id' => $unit->id])->assertForbidden();
        $this->assertDatabaseHas('loyalty_dues', ['id' => $due->id, 'status' => 'PENDENTE']);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_dependent_books_with_benefit_and_cancelation_removes_it(): void
    {
        $offering = $this->loyaltyOffering();
        $plan = LoyaltyPlan::query()->where('agreement_id', $offering->agreement_id)->firstOrFail();
        $this->travelTo(CarbonImmutable::parse('2026-09-02T07:00:00-03:00'));
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $subscriptionId = $this->postJson('/api/v1/team/loyalty/subscriptions', [
            'plan_id' => $plan->id,
            'patient' => ['cpf' => '123.456.789-09', 'name' => 'Titular', 'phone' => '85999999999'],
            'dependents' => [['cpf' => '11144477735', 'name' => 'Dependente']],
        ])->assertCreated()->json('data.id');
        $this->actingAs(User::factory()->create());
        $dependentPayload = $this->bookingPayload($offering);
        $dependentPayload['patient'] = ['name' => 'Dependente', 'cpf' => '11144477735', 'phone' => '85988888888', 'birth_date' => '2010-05-05'];
        $appointmentId = $this->postJson('/api/v1/team/appointments', $dependentPayload)->assertCreated()->json('data.id');
        $this->assertDatabaseHas('appointments', ['id' => $appointmentId, 'price_snapshot' => '65.00']);
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $this->patchJson('/api/v1/team/loyalty/subscriptions/'.$subscriptionId, ['status' => 'CANCELED'])->assertOk()
            ->assertJsonPath('data.status', 'CANCELED')->assertJsonPath('data.ended_at', '2026-09-02');
        $this->actingAs(User::factory()->create());
        $this->postJson('/api/v1/team/appointments', [...$dependentPayload, 'idempotency_key' => (string) Str::uuid(), 'starts_at' => '2026-09-07T08:30:00-03:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('patient');
    }

    public function test_eligibility_endpoint_reports_plan_state(): void
    {
        $offering = $this->loyaltyOffering();
        $plan = LoyaltyPlan::query()->where('agreement_id', $offering->agreement_id)->firstOrFail();
        $this->travelTo(CarbonImmutable::parse('2026-09-02T07:00:00-03:00'));
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $data = $this->postJson('/api/v1/team/loyalty/subscriptions', [
            'plan_id' => $plan->id,
            'patient' => ['cpf' => '123.456.789-09', 'name' => 'Titular', 'phone' => '85999999999'],
        ])->assertCreated()->json('data');
        $patientId = $data['patient']['id'];
        $this->getJson('/api/v1/team/loyalty/eligibility?agreement_id='.$offering->agreement_id.'&patient_id='.$patientId)
            ->assertOk()->assertJsonPath('data.eligible', true)->assertJsonPath('data.plan.name', 'SOU DEZ COMPLETO');
        LoyaltyDue::factory()->overdue()->create(['subscription_id' => $data['id']]);
        $this->getJson('/api/v1/team/loyalty/eligibility?agreement_id='.$offering->agreement_id.'&patient_id='.$patientId)
            ->assertOk()->assertJsonPath('data.eligible', false);
        $other = Agreement::factory()->create();
        $this->getJson('/api/v1/team/loyalty/eligibility?agreement_id='.$other->id.'&patient_id='.$patientId)
            ->assertOk()->assertJsonPath('data.eligible', true)->assertJsonPath('data.plan', null);
    }

    public function test_subscriptions_list_searches_by_cpf_and_updates_dependents(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-02T07:00:00-03:00'));
        $plan = LoyaltyPlan::factory()->create();
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $data = $this->postJson('/api/v1/team/loyalty/subscriptions', [
            'plan_id' => $plan->id,
            'patient' => ['cpf' => '123.456.789-09', 'name' => 'Titular Busca', 'phone' => '85999999999'],
            'dependents' => [['cpf' => '11144477735', 'name' => 'Dependente Inicial']],
        ])->assertCreated()->json('data');
        $this->getJson('/api/v1/team/loyalty/subscriptions?search=12345678909')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/team/loyalty/subscriptions?search=Titular Busca')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/team/loyalty/subscriptions?search=sem-resultado')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson('/api/v1/team/loyalty/subscriptions/'.$data['id'], [
            'dependents' => [['cpf' => '52998224772', 'name' => 'Dependente Novo']],
        ])->assertOk();
        $pepper = (string) config('sistema_dez.identity_pepper');
        $newId = Patient::query()->where('cpf_hash', hash_hmac('sha256', '52998224772', $pepper))->value('id');
        $oldId = Patient::query()->where('cpf_hash', hash_hmac('sha256', '11144477735', $pepper))->value('id');
        $this->assertDatabaseHas('loyalty_dependents', ['subscription_id' => $data['id'], 'patient_id' => $newId, 'active' => true]);
        $this->assertDatabaseHas('loyalty_dependents', ['subscription_id' => $data['id'], 'patient_id' => $oldId, 'active' => false]);
        $this->patchJson('/api/v1/team/loyalty/subscriptions/'.$data['id'], ['notes' => 'Sem dependentes agora'])->assertOk();
        $this->assertDatabaseHas('loyalty_dependents', ['subscription_id' => $data['id'], 'patient_id' => $newId, 'active' => true]);
        $this->assertDatabaseHas('loyalty_subscriptions', ['id' => $data['id'], 'notes' => 'Sem dependentes agora']);
    }

    public function test_dues_listing_marks_overdue(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-02T07:00:00-03:00'));
        $plan = LoyaltyPlan::factory()->create();
        $subscription = LoyaltySubscription::factory()->create(['plan_id' => $plan->id]);
        $overdue = LoyaltyDue::factory()->overdue()->create(['subscription_id' => $subscription->id]);
        LoyaltyDue::factory()->create(['subscription_id' => $subscription->id]);
        LoyaltyDue::factory()->paid()->create(['subscription_id' => $subscription->id]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->getJson('/api/v1/team/loyalty/dues?overdue=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $overdue->id);
        $this->getJson('/api/v1/team/loyalty/dues?status=PENDENTE')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/team/loyalty/dues?status=PAGA')->assertOk()->assertJsonCount(1, 'data');
    }

    private function loyaltyOffering(): ServiceOffering
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-01T07:00:00-03:00'));
        $agreement = Agreement::factory()->create(['legacy_id' => '71', 'name' => 'SOU DEZ COMPLETO', 'type' => 'PLAN']);
        LoyaltyPlan::factory()->create(['name' => 'SOU DEZ COMPLETO', 'agreement_id' => $agreement->id, 'monthly_fee' => '89.90', 'due_day' => 10]);
        $price = ProcedurePrice::factory()->create(['agreement_id' => $agreement->id, 'amount' => '65.00']);
        $price->unit->update(['operational_enabled' => true]);
        $professional = Professional::factory()->create();
        $link = CatalogLink::create(['unit_id' => $price->unit_id, 'procedure_id' => $price->procedure_id, 'agreement_id' => $agreement->id, 'professional_id' => $professional->id, 'active' => true]);
        $offering = ServiceOffering::factory()->create(['unit_id' => $link->unit_id, 'procedure_id' => $link->procedure_id, 'professional_id' => $professional->id, 'agreement_id' => $agreement->id, 'price' => '65.00', 'online_enabled' => false, 'catalog_link_id' => $link->id, 'procedure_price_id' => $price->id]);
        $offering->scheduleWindows()->create(['effective_from' => '2026-09-01', 'effective_to' => null, 'weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '10:00', 'slot_minutes' => 30, 'daily_capacity_override' => null, 'active' => true]);
        app(MaterializeScheduleOccurrences::class)->handle($offering);

        return $offering;
    }

    /** @return array<string, mixed> */
    private function bookingPayload(ServiceOffering $offering): array
    {
        return ['idempotency_key' => (string) Str::uuid(), 'offering_id' => $offering->id, 'starts_at' => '2026-09-07T08:00:00-03:00', 'patient' => ['name' => 'Paciente Piloto', 'cpf' => '123.456.789-09', 'phone' => '85999999999', 'birth_date' => '1990-01-01'], 'privacy_consent' => true];
    }

    /** @return array<string, mixed> */
    private function planPayload(Agreement $agreement): array
    {
        return ['name' => 'SOU DEZ COMPLETO', 'agreement_id' => $agreement->id, 'monthly_fee' => '89.90', 'due_day' => 10, 'active' => true];
    }
}
