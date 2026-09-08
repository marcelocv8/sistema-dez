<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\Professional;
use App\Models\ReportTemplate;
use App\Models\User;
use App\Services\Stg\ReportTemplateImporter;
use Database\Factories\ServiceOfferingFactory;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(): string
    {
        return dirname(__DIR__).'/Fixtures/laudos';
    }

    public function test_reception_imports_templates_and_runs_full_flow(): void
    {
        $doctorUser = User::factory()->create(['role' => UserRole::Doctor, 'name' => 'Emmanuel Gonçalves de Castro Andrade']);
        $professional = Professional::factory()->create(['name' => 'Emmanuel Gonçalves de Castro Andrade', 'user_id' => $doctorUser->id, 'council_acronym' => 'CRM', 'council_number' => '12345']);
        $procedure = Procedure::factory()->create(['name' => 'TRATAMENTO FISIOTERAPIA 1 SEG.']);
        $ecoProcedure = Procedure::factory()->create(['name' => 'ECOCARDIOGRAMA']);

        $importer = new ReportTemplateImporter;
        $summary = $importer->import($this->fixture(), true);
        $this->assertSame(3, $summary['created']);
        $this->assertSame(1, $summary['linked_doctors']);
        $this->assertSame(2, $summary['linked_procedures']);
        $this->assertSame(2, ReportTemplate::query()->where('legacy_type', 'LAUDO')->count());
        $this->assertSame(1, ReportTemplate::query()->where('legacy_type', 'LINHA')->count());
        $this->assertSame($professional->id, ReportTemplate::query()->where('legacy_type', 'LAUDO')->where('legacy_id', 703)->value('doctor_id'));
        $this->assertSame($procedure->id, ReportTemplate::query()->where('legacy_type', 'LAUDO')->where('legacy_id', 703)->value('procedure_id'));
        $this->assertSame($ecoProcedure->id, ReportTemplate::query()->where('legacy_type', 'LAUDO')->where('legacy_id', 704)->value('procedure_id'));
        $summaryAgain = $importer->import($this->fixture(), true);
        $this->assertSame(0, $summaryAgain['created']);
        $this->assertSame(3, ReportTemplate::query()->count());

        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $templates = $this->getJson('/api/v1/team/reports/templates')->assertOk()->json('data');
        $this->assertCount(3, $templates);

        $patient = Patient::factory()->create(['name' => 'Maria Laudo', 'birth_date' => '1990-05-10']);
        $appointment = Appointment::factory()->create(['status' => 'CHECKED_IN', 'payment_status' => 'PAID', 'care_started_at' => now(),
            'patient_id' => $patient->id,
            'service_offering_id' => ServiceOfferingFactory::new()->create(['procedure_id' => $ecoProcedure->id, 'professional_id' => $professional->id])->id,
        ]);

        $template704 = ReportTemplate::query()->where('legacy_id', 704)->firstOrFail();
        $report = $this->postJson('/api/v1/team/reports', [
            'appointment_id' => $appointment->id, 'template_id' => $template704->id,
        ])->assertCreated()->json('data');
        $this->assertSame('RASCUNHO', $report['status']);
        $this->assertSame($patient->id, $report['patient_id']);
        $this->assertStringContainsString('Maria Laudo', $report['content_html']);
        $this->assertStringContainsString('ECOCARDIOGRAMA', (string) $report['procedure']['name']);

        $this->putJson("/api/v1/team/reports/{$report['id']}", [
            'content_html' => '<p>ECO revisado.</p>',
        ])->assertOk();

        $finalized = $this->postJson("/api/v1/team/reports/{$report['id']}/finalize")->assertOk()->json('data');
        $this->assertSame('FINALIZADO', $finalized['status']);
        $this->assertNotNull($finalized['retention_until']);
        $this->assertSame(now()->addYears(20)->toDateString(), $finalized['retention_until']);

        $printed = $this->postJson("/api/v1/team/reports/{$report['id']}/print")->assertOk()->json('data');
        $this->assertSame(1, $printed['print_count']);
        $this->assertStringContainsString('Clínica DEZ', $printed['print_html']);
        $this->assertStringContainsString('Maria Laudo', $printed['print_html']);
        $printedAgain = $this->postJson("/api/v1/team/reports/{$report['id']}/print")->assertOk()->json('data');
        $this->assertSame(2, $printedAgain['print_count']);
        $events = $this->getJson("/api/v1/team/reports/{$report['id']}")->assertOk()->json('data.events');
        $this->assertSame(['CRIADO', 'SALVO', 'FINALIZADO', 'IMPRESSO', 'REIMPRESSO'], array_column($events, 'event'));

        $this->actingAs($doctorUser);
        $signed = $this->postJson("/api/v1/team/reports/{$report['id']}/sign-manual")->assertOk()->json('data');
        $this->assertTrue($signed['signed_manual']);
        $this->assertSame(['CRIADO', 'SALVO', 'FINALIZADO', 'IMPRESSO', 'REIMPRESSO', 'ASSINATURA_MANUAL'],
            array_column($this->getJson("/api/v1/team/reports/{$report['id']}")->assertOk()->json('data.events'), 'event'));

        $history = $this->getJson("/api/v1/team/reports/history?patient_id={$patient->id}")->assertOk()->json('data');
        $this->assertCount(1, $history);
        $this->assertSame('FINALIZADO', $history[0]['status']);
        $this->assertSame('Emmanuel Gonçalves de Castro Andrade', $history[0]['doctor']['name']);
    }

    public function test_rbac_and_state_rules_for_reports(): void
    {
        $reception = User::factory()->create(['role' => UserRole::Reception]);
        $cashier = User::factory()->create(['role' => UserRole::Cashier]);
        $this->actingAs($reception);

        $template = ReportTemplate::factory()->create([
            'legacy_type' => 'LAUDO', 'legacy_id' => 900, 'nome' => 'T', 'content_html' => '<p>x</p>',
        ]);
        $appointment = Appointment::factory()->create(['status' => 'CHECKED_IN', 'payment_status' => 'PAID', 'care_started_at' => now()]);
        $report = $this->postJson('/api/v1/team/reports', [
            'appointment_id' => $appointment->id, 'template_id' => $template->id,
        ])->assertCreated()->json('data');

        $this->actingAs($cashier);
        $this->postJson('/api/v1/team/reports', [
            'appointment_id' => $appointment->id, 'template_id' => $template->id,
        ])->assertForbidden();
        $this->actingAs($reception);
        $this->postJson("/api/v1/team/reports/{$report['id']}/sign-manual")->assertForbidden();

        $this->postJson("/api/v1/team/reports/{$report['id']}/finalize")->assertOk();
        $this->postJson("/api/v1/team/reports/{$report['id']}/finalize")->assertUnprocessable();
        $this->putJson("/api/v1/team/reports/{$report['id']}", ['content_html' => '<p>y</p>'])->assertUnprocessable();
        $this->postJson("/api/v1/team/reports/{$report['id']}/append", ['adendo' => 'Revisão complementar do laudo.'])->assertOk()
            ->assertJsonPath('data.adendo', 'Revisão complementar do laudo.');
    }
}
