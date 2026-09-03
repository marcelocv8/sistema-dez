<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Agreement;
use App\Models\Procedure;
use App\Models\Professional;
use App\Models\StgImportIssue;
use App\Models\StgImportRun;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamCatalogManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_reception_can_view_catalog_but_cannot_change_it(): void
    {
        $reception = User::factory()->create(['role' => UserRole::Reception]);
        Procedure::factory()->create(['name' => 'Consulta Cardiológica', 'search_name' => 'consulta cardiologica']);

        $this->actingAs($reception)->getJson('/api/v1/team/catalog/procedures?q=cardiologica')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($reception)->postJson('/api/v1/team/catalog/procedures', $this->procedurePayload())
            ->assertForbidden();
    }

    public function test_supervisor_can_create_update_and_deactivate_catalog_with_audit(): void
    {
        $supervisor = User::factory()->create(['role' => UserRole::Supervisor]);

        $created = $this->actingAs($supervisor)->postJson('/api/v1/team/catalog/procedures', $this->procedurePayload())
            ->assertCreated()->assertJsonPath('data.name', 'Eco Doppler Manual')->json('data');

        $this->putJson('/api/v1/team/catalog/procedures/'.$created['id'], $this->procedurePayload(['duration_minutes' => 45]))
            ->assertOk()->assertJsonPath('data.duration_minutes', 45);
        $this->deleteJson('/api/v1/team/catalog/procedures/'.$created['id'])
            ->assertOk()->assertJsonPath('data.active', false);

        $this->assertDatabaseCount('service_offerings', 0);
        $this->assertDatabaseCount('audit_logs', 3);
        $this->assertDatabaseHas('audit_logs', ['entity_key' => $created['id'], 'action' => 'deactivated']);
    }

    public function test_supervisor_manages_catalog_link_without_publishing_service_offering(): void
    {
        $supervisor = User::factory()->create(['role' => UserRole::Supervisor]);
        $unit = Unit::factory()->create(['active' => true]);
        $procedure = Procedure::factory()->create(['active' => true]);
        $professional = Professional::factory()->create(['active' => true]);
        $agreement = Agreement::factory()->create(['active' => true]);
        $payload = [
            'unit_id' => $unit->id,
            'procedure_id' => $procedure->id,
            'professional_id' => $professional->id,
            'agreement_id' => $agreement->id,
            'active' => true,
        ];

        $this->actingAs($supervisor)->postJson('/api/v1/team/catalog-links', $payload)
            ->assertCreated()->assertJsonPath('data.professional.id', $professional->id);
        $this->postJson('/api/v1/team/catalog-links', $payload)->assertUnprocessable();

        $this->assertDatabaseCount('catalog_links', 1);
        $this->assertDatabaseCount('service_offerings', 0);
    }

    public function test_default_agreement_cannot_be_deactivated_without_replacement(): void
    {
        $supervisor = User::factory()->create(['role' => UserRole::Supervisor]);
        $agreement = Agreement::factory()->create(['active' => true, 'is_default' => true]);

        $this->actingAs($supervisor)->deleteJson('/api/v1/team/catalog/agreements/'.$agreement->id)
            ->assertUnprocessable()->assertJsonValidationErrors('is_default');

        $this->assertTrue($agreement->fresh()->active);
        $this->assertTrue($agreement->fresh()->is_default);
    }

    public function test_supervisor_resolves_latest_import_issue_and_action_is_audited(): void
    {
        $supervisor = User::factory()->create(['role' => UserRole::Supervisor]);
        $run = StgImportRun::create([
            'checksum' => str_repeat('a', 64),
            'status' => 'COMMITTED',
            'summary' => [],
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $issue = StgImportIssue::create([
            'import_run_id' => $run->id,
            'code' => 'LEGACY_ID_NOT_FOUND',
            'entity_type' => 'procedure',
            'source_file' => 'fixture.csv',
            'payload' => ['nome' => 'Sem ID'],
        ]);

        $this->actingAs($supervisor)->getJson('/api/v1/team/import-issues?status=OPEN')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->putJson('/api/v1/team/import-issues/'.$issue->id, [
            'resolution_status' => 'RESOLVED',
            'resolution_note' => 'Conciliado manualmente com o cadastro correto.',
        ])->assertOk()->assertJsonPath('data.resolution_status', 'RESOLVED');

        $this->assertDatabaseHas('stg_import_issues', ['id' => $issue->id, 'resolved_by' => $supervisor->id]);
        $this->assertDatabaseHas('audit_logs', ['entity_key' => $issue->id, 'action' => 'resolution_updated']);
    }

    public function test_only_admin_manages_users_and_cannot_remove_own_admin_access(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $supervisor = User::factory()->create(['role' => UserRole::Supervisor]);
        $payload = [
            'name' => 'Nova Recepção',
            'email' => 'nova.recepcao@dez.test',
            'role' => 'RECEPTION',
            'active' => true,
            'password' => 'Senha-Segura-2026',
        ];

        $this->actingAs($supervisor)->postJson('/api/v1/team/users', $payload)->assertForbidden();
        $this->actingAs($admin)->postJson('/api/v1/team/users', $payload)
            ->assertCreated()->assertJsonMissingPath('data.password');
        $this->putJson('/api/v1/team/users/'.$admin->id, [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => 'SUPERVISOR',
            'active' => true,
            'password' => null,
        ])->assertUnprocessable()->assertJsonValidationErrors('role');
    }

    /** @param array<string, mixed> $override @return array<string, mixed> */
    private function procedurePayload(array $override = []): array
    {
        return array_merge([
            'legacy_id' => null,
            'name' => 'Eco Doppler Manual',
            'type' => 'EXAM',
            'group_name' => 'ECOCARDIOGRAMA',
            'tuss_code' => '40901010',
            'duration_minutes' => 30,
            'active' => true,
            'legacy' => false,
            'online_enabled' => false,
        ], $override);
    }
}
