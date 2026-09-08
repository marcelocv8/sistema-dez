<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\PortalAccess;
use App\Models\Report;
use App\Models\ReportTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PortalAccessTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function finalizedReport(Patient $patient, int $legacyId = 500): Report
    {
        $reception = User::factory()->create(['role' => UserRole::Reception]);
        $this->actingAs($reception);
        $appointment = Appointment::factory()->create(['status' => 'CHECKED_IN', 'payment_status' => 'PAID', 'care_started_at' => now(), 'patient_id' => $patient->id]);
        $template = ReportTemplate::query()->create([
            'legacy_type' => 'LAUDO', 'legacy_id' => $legacyId, 'nome' => "ECO T {$legacyId}", 'content_html' => '<p>Normal.</p>',
        ]);
        $report = $this->postJson('/api/v1/team/reports', [
            'appointment_id' => $appointment->id, 'template_id' => $template->id,
        ])->assertCreated()->json('data');
        $finalized = $this->postJson("/api/v1/team/reports/{$report['id']}/finalize")->assertOk()->json('data');

        return Report::query()->findOrFail($finalized['id']);
    }

    public function test_publish_generates_credential_and_portal_login_shows_results(): void
    {
        $patient = Patient::factory()->create(['name' => 'Maria Portal']);
        $report = $this->finalizedReport($patient);

        $publication = $this->postJson("/api/v1/team/reports/{$report->id}/portal")->assertOk()->json('data');
        $this->assertTrue($publication['portal_published']);
        $this->assertNotNull($publication['login']);
        $this->assertNotNull($publication['password']);
        $this->assertStringContainsString('Clínica DEZ', $publication['folha_html']);
        $this->assertStringContainsString($publication['login'], $publication['folha_html']);
        $this->assertStringContainsString('resultados', $publication['folha_html']);
        $this->assertStringContainsString('data:image/svg+xml;base64,', $publication['folha_html']);

        $second = $this->postJson("/api/v1/team/reports/{$report->id}/portal")->assertOk()->json('data');
        $this->assertSame($publication['login'], $second['login']);
        $this->assertNull($second['password']);

        $login = $this->postJson('/api/v1/public/portal/login', [
            'login' => $publication['login'], 'password' => $publication['password'],
        ])->assertOk()->json('data');
        $this->assertSame('Maria Portal', $login['patient_name']);

        $results = $this->getJson('/api/v1/public/portal/results', ['Authorization' => 'Bearer '.$login['token']])
            ->assertOk()->json('data');
        $this->assertCount(1, $results['results']);
        $this->assertStringContainsString('Normal.', $results['results'][0]['content_html']);
        $this->assertSame(1, PortalAccess::query()->value('access_count'));

        $this->getJson('/api/v1/public/portal/results')->assertUnauthorized();
        $this->getJson('/api/v1/public/portal/results', ['Authorization' => 'Bearer token-invalido'])->assertUnauthorized();
        $this->postJson('/api/v1/public/portal/login', [
            'login' => $publication['login'], 'password' => 'errada-12345',
        ])->assertUnprocessable();
    }

    public function test_expired_or_revoked_credential_and_unpublish_block_access(): void
    {
        $patient = Patient::factory()->create(['name' => 'Joao Portal']);
        $report = $this->finalizedReport($patient);
        $publication = $this->postJson("/api/v1/team/reports/{$report->id}/portal")->assertOk()->json('data');

        PortalAccess::query()->firstOrFail()->forceFill(['expires_at' => now()->subDay()])->save();
        $this->postJson('/api/v1/public/portal/login', [
            'login' => $publication['login'], 'password' => $publication['password'],
        ])->assertUnprocessable();

        $report2 = $this->finalizedReport($patient, 502);
        $publication2 = $this->postJson("/api/v1/team/reports/{$report2->id}/portal")->assertOk()->json('data');
        $login2 = $this->postJson('/api/v1/public/portal/login', [
            'login' => $publication2['login'], 'password' => $publication2['password'],
        ])->assertOk()->json('data');
        $this->postJson("/api/v1/team/reports/{$report2->id}/portal/revoke", [
            'reason' => 'Paciente pediu retirada do resultado do portal',
        ])->assertOk()->assertJsonPath('data.portal_published', false);
        $this->getJson('/api/v1/public/portal/results', ['Authorization' => 'Bearer '.$login2['token']])
            ->assertOk()->assertJsonPath('data.results', []);
    }

    public function test_draft_cannot_be_published(): void
    {
        $patient = Patient::factory()->create();
        $reception = User::factory()->create(['role' => UserRole::Reception]);
        $this->actingAs($reception);
        $appointment = Appointment::factory()->create(['status' => 'CHECKED_IN', 'payment_status' => 'PAID', 'care_started_at' => now(), 'patient_id' => $patient->id]);
        $template = ReportTemplate::query()->create([
            'legacy_type' => 'LAUDO', 'legacy_id' => 501, 'nome' => 'ECO T2', 'content_html' => '<p>Rascunho.</p>',
        ]);
        $report = Report::query()->create([
            'appointment_id' => $appointment->id, 'patient_id' => $patient->id, 'template_id' => $template->id,
            'content_html' => '<p>Rascunho.</p>', 'status' => Report::STATUS_RASCUNHO, 'typed_by' => $reception->id,
        ]);

        $this->postJson("/api/v1/team/reports/{$report->id}/portal")->assertUnprocessable();
    }
}
