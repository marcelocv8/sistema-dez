<?php

namespace Tests\Feature;

use App\Actions\Scheduling\MaterializeScheduleOccurrences;
use App\Enums\AsoType;
use App\Enums\UserRole;
use App\Models\Agreement;
use App\Models\Appointment;
use App\Models\CatalogLink;
use App\Models\MtAso;
use App\Models\MtAsoEvent;
use App\Models\MtFunction;
use App\Models\MtRisk;
use App\Models\MtSector;
use App\Models\Patient;
use App\Models\ProcedurePrice;
use App\Models\Professional;
use App\Models\ReportTemplate;
use App\Models\RoutineTask;
use App\Models\ServiceOffering;
use App\Models\User;
use App\Services\RoutineGenerator;
use Carbon\CarbonImmutable;
use Database\Factories\ServiceOfferingFactory;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class MtTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_reception_manages_mt_catalog_and_rbac_blocks_cashier_writes(): void
    {
        $agreement = Agreement::factory()->create();
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));

        $options = $this->getJson('/api/v1/team/mt/options')->assertOk()->json('data');
        $this->assertSame($agreement->id, $options['agreements'][0]['id']);

        $risk = $this->postJson('/api/v1/team/mt/risks', ['name' => 'Ruído'])->assertCreated()->json('data');
        $sector = $this->postJson('/api/v1/team/mt/sectors', ['agreement_id' => $agreement->id, 'name' => 'Produção'])->assertCreated()->json('data');
        $function = $this->postJson('/api/v1/team/mt/functions', ['agreement_id' => $agreement->id, 'sector_id' => $sector['id'], 'risk_id' => $risk['id'], 'name' => 'Operador'])->assertCreated()->json('data');

        $this->assertStringContainsString('Produção', $function['sector']['name']);
        $this->putJson('/api/v1/team/mt/risks/'.$risk['id'], ['name' => 'Ruído contínuo'])->assertOk();
        $this->postJson('/api/v1/team/mt/sectors', ['agreement_id' => $agreement->id, 'name' => 'Produção'])->assertUnprocessable();

        $this->actingAs(User::factory()->create(['role' => UserRole::Cashier]));
        $this->getJson('/api/v1/team/mt/risks')->assertOk();
        $this->postJson('/api/v1/team/mt/risks', ['name' => 'Químico'])->assertForbidden();
        $this->getJson('/api/v1/team/mt/asos')->assertOk();
    }

    public function test_reception_creates_updates_cancels_and_tracks_aso_events(): void
    {
        $agreement = Agreement::factory()->create();
        $patient = Patient::factory()->create();
        $risk = MtRisk::factory()->create();
        $sector = MtSector::factory()->create(['agreement_id' => $agreement->id]);
        $function = MtFunction::factory()->create(['agreement_id' => $agreement->id, 'sector_id' => $sector->id]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));

        $aso = $this->postJson('/api/v1/team/mt/asos', [
            'patient_id' => $patient->id,
            'agreement_id' => $agreement->id,
            'sector_id' => $sector->id,
            'function_id' => $function->id,
            'risks' => [$risk->id],
            'type' => 'PERIODICO',
            'notes' => 'Troca de função em janeiro.',
        ])->assertCreated()->json('data');

        $this->assertSame('PENDENTE', $aso['status']);
        $this->assertSame('Periódico', $aso['type_label']);
        $this->assertSame([$risk->id], array_column($aso['risks'], 'id'));

        $this->putJson('/api/v1/team/mt/asos/'.$aso['id'], [
            'type' => 'DEMISSIONAL',
            'sector_id' => $sector->id,
            'function_id' => $function->id,
            'notes' => 'Demitido sem justa causa.',
        ])->assertOk();

        $this->postJson('/api/v1/team/mt/asos/'.$aso['id'].'/cancel', ['reason' => 'Empresa encerrou o contrato.'])->assertOk();
        $this->postJson('/api/v1/team/mt/asos/'.$aso['id'].'/extend', ['expires_at' => '2027-12-31'])->assertUnprocessable();

        $this->assertDatabaseHas('mt_aso_events', ['mt_aso_id' => $aso['id'], 'event' => MtAsoEvent::CREATED]);
        $this->assertDatabaseHas('mt_aso_events', ['mt_aso_id' => $aso['id'], 'event' => MtAsoEvent::UPDATED]);
        $this->assertDatabaseHas('mt_aso_events', ['mt_aso_id' => $aso['id'], 'event' => MtAsoEvent::CANCELLED]);
    }

    public function test_due_lists_periodic_asos_with_overdue_flag(): void
    {
        $agreement = Agreement::factory()->create();
        $this->actingAs(User::factory()->create(['role' => UserRole::Manager]));

        MtAso::factory()->emitted()->create(['agreement_id' => $agreement->id, 'expires_at' => now()->subDays(5)->toDateString()]);
        MtAso::factory()->emitted()->create(['agreement_id' => $agreement->id, 'expires_at' => now()->addDays(10)->toDateString()]);
        MtAso::factory()->create(['agreement_id' => $agreement->id, 'type' => AsoType::Admissional, 'expires_at' => now()->addDay()->toDateString()]);

        $due = $this->getJson('/api/v1/team/mt/asos/due')->assertOk()->json('data');
        $this->assertSame(30, $due['days']);
        $this->assertCount(2, $due['items']);
        $this->assertTrue($due['items'][0]['overdue']);
        $this->assertLessThan(0, $due['items'][0]['days_until_due']);
        $this->assertFalse($due['items'][1]['overdue']);

        $filtered = $this->getJson('/api/v1/team/mt/asos/due?days=7')->assertOk()->json('data');
        $this->assertCount(1, $filtered['items']);
    }

    public function test_batch_schedule_books_and_reports_conflicts_per_item(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-02T07:00:00-03:00'));
        $offering = $this->offeringWithWindow();
        $patients = Patient::factory()->count(3)->create();
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));

        $response = $this->postJson('/api/v1/team/mt/batch-schedule', [
            'offering_id' => $offering->id,
            'items' => [
                ['patient_id' => $patients[0]->id, 'starts_at' => '2026-09-07T08:00:00-03:00'],
                ['patient_id' => $patients[1]->id, 'starts_at' => '2026-09-07T08:30:00-03:00'],
                ['patient_id' => $patients[2]->id, 'starts_at' => '2026-09-07T08:00:00-03:00'],
            ],
        ])->assertOk()->json('data');

        $this->assertSame(2, $response['scheduled']);
        $this->assertSame(1, $response['failed']);
        $this->assertSame('error', $response['results'][2]['status']);
        $this->assertDatabaseCount('appointments', 2);
        $this->assertSame($patients[0]->id, Appointment::first()->patient_id);
        $this->assertSame('RECEPTION', Appointment::first()->origin);
    }

    public function test_emit_creates_report_with_mt_variables_and_blocks_duplicates(): void
    {
        $agreement = Agreement::factory()->create();
        $template = ReportTemplate::factory()->create([
            'nome' => 'ASO Modelo',
            'content_html' => '<p>{{PACIENTE_NOME}} — {{EMPRESA}} / {{SETOR}} / {{FUNCAO}} / {{RISCO}} — {{TIPO_ASO}}</p>',
        ]);
        $patient = Patient::factory()->create();
        $risk = MtRisk::factory()->create();
        $sector = MtSector::factory()->create(['agreement_id' => $agreement->id]);
        $function = MtFunction::factory()->create(['agreement_id' => $agreement->id, 'sector_id' => $sector->id]);
        $appointment = Appointment::factory()->create(['patient_id' => $patient->id, 'status' => 'CHECKED_IN']);
        $aso = MtAso::factory()->create([
            'patient_id' => $patient->id,
            'agreement_id' => $agreement->id,
            'sector_id' => $sector->id,
            'function_id' => $function->id,
            'type' => AsoType::Periodico,
        ]);
        $aso->risks()->sync([$risk->id]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));

        $payload = $this->postJson('/api/v1/team/mt/asos/'.$aso->id.'/emit', [
            'appointment_id' => $appointment->id,
            'template_id' => $template->id,
        ])->assertCreated()->json('data');

        $this->assertSame('EMITIDO', $payload['aso']['status']);
        $this->assertNotNull($payload['aso']['expires_at']);
        $this->assertSame($patient->name, $payload['aso']['patient']['name']);
        $this->assertStringContainsString($patient->name, $payload['report']['content_html']);
        $this->assertStringContainsString($agreement->name, $payload['report']['content_html']);
        $this->assertStringContainsString($sector->name, $payload['report']['content_html']);
        $this->assertStringContainsString($function->name, $payload['report']['content_html']);
        $this->assertStringContainsString($risk->name, $payload['report']['content_html']);
        $this->assertStringContainsString('Periódico', $payload['report']['content_html']);
        $this->assertDatabaseHas('mt_aso_events', ['mt_aso_id' => $aso->id, 'event' => MtAsoEvent::ISSUED]);

        $this->postJson('/api/v1/team/mt/asos/'.$aso->id.'/emit', [
            'appointment_id' => $appointment->id,
        ])->assertUnprocessable();
    }

    public function test_emit_requires_linked_exam_and_company_report_counts(): void
    {
        $agreement = Agreement::factory()->create();
        $aso = MtAso::factory()->create(['agreement_id' => $agreement->id, 'appointment_id' => null]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Manager]));

        $this->postJson('/api/v1/team/mt/asos/'.$aso->id.'/emit')->assertUnprocessable();

        MtAso::factory()->emitted()->create(['agreement_id' => $agreement->id, 'expires_at' => now()->addDays(20)->toDateString()]);
        MtAso::factory()->emitted()->create(['agreement_id' => $agreement->id, 'expires_at' => now()->addDays(60)->toDateString()]);

        $report = $this->getJson('/api/v1/team/mt/companies/'.$agreement->id.'/report')->assertOk()->json('data');
        $this->assertSame($agreement->name, $report['agreement']['name']);
        $this->assertSame(3, $report['total_asos']);
        $this->assertSame(2, $report['by_status']['EMITIDO']);
        $this->assertSame(1, $report['by_status']['PENDENTE']);
        $this->assertCount(1, $report['due_soon']);
    }

    public function test_routine_generator_creates_aso_renewal_task_for_overdue_periodic(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-07T07:00:00-03:00'));
        MtAso::factory()->emitted()->create(['expires_at' => '2026-09-05']);
        MtAso::factory()->emitted()->create(['expires_at' => '2026-09-10']);

        $created = app(RoutineGenerator::class)->generate(CarbonImmutable::parse('2026-09-07'));

        $this->assertSame(1, $created);
        $this->assertDatabaseHas('routine_tasks', ['type' => RoutineTask::ASO_VENCENDO]);
    }

    private function offering(): ServiceOffering
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-02T07:00:00-03:00'));
        $price = ProcedurePrice::factory()->create(['amount' => '150.00']);
        $price->unit->update(['operational_enabled' => true]);
        $professional = Professional::factory()->create();
        $link = CatalogLink::create(['unit_id' => $price->unit_id, 'procedure_id' => $price->procedure_id, 'agreement_id' => $price->agreement_id, 'professional_id' => $professional->id, 'active' => true]);

        return ServiceOfferingFactory::new()->create([
            'unit_id' => $link->unit_id,
            'procedure_id' => $link->procedure_id,
            'professional_id' => $link->professional_id,
            'agreement_id' => $link->agreement_id,
            'catalog_link_id' => $link->id,
            'procedure_price_id' => $price->id,
            'price' => '150.00',
            'online_enabled' => false,
            'booking_mode' => 'FIXED_SLOT',
            'modality' => 'PRESENTIAL',
            'duration_override_minutes' => 30,
            'daily_capacity' => 10,
            'arrival_block_minutes' => 60,
            'arrival_block_capacity' => 3,
            'active' => true,
        ]);
    }

    private function offeringWithWindow(): ServiceOffering
    {
        $offering = $this->offering();
        $offering->scheduleWindows()->create([
            'effective_from' => '2026-09-01', 'effective_to' => null, 'weekday' => 1,
            'starts_at' => '08:00', 'ends_at' => '10:00', 'slot_minutes' => 30,
            'daily_capacity_override' => null, 'active' => true,
        ]);
        app(MaterializeScheduleOccurrences::class)->handle($offering);

        return $offering;
    }
}
