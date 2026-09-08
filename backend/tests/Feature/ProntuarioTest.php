<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AiRequest;
use App\Models\Appointment;
use App\Models\ClinicalNote;
use App\Models\Patient;
use App\Models\PatientAlert;
use App\Models\ProntuarioEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProntuarioTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_doctor_uses_timeline_notes_and_alerts_with_full_audit(): void
    {
        $doctorUser = User::factory()->create(['role' => UserRole::Doctor]);
        $this->actingAs($doctorUser);
        $patient = Patient::factory()->create(['name' => 'Maria Prontuario', 'birth_date' => '1980-03-12']);
        $appointment = Appointment::factory()->create(['patient_id' => $patient->id, 'status' => 'DONE']);

        $timeline = $this->getJson("/api/v1/team/prontuario?patient_id={$patient->id}")->assertOk()->json('data');
        $this->assertSame('Maria Prontuario', $timeline['patient']['name']);
        $this->assertCount(1, $timeline['appointments']);
        $this->assertSame('DONE', $timeline['appointments'][0]['status']);
        $this->assertSame([], $timeline['notes']);
        $this->assertSame([], $timeline['alerts']);

        $note = $this->postJson('/api/v1/team/prontuario/notes', [
            'appointment_id' => $appointment->id,
            'kind' => ClinicalNote::KIND_EVOLUCAO,
            'content_html' => '<p>Paciente com cefaleia tensional; orientada analgesia e retorno em 30 dias.</p>',
        ])->assertCreated()->json('data');
        $this->assertSame('EVOLUCAO', $note['kind']);
        $this->assertNotNull($note['retention_until']);
        $this->assertSame(now()->addYears(20)->toDateString(), $note['retention_until']);

        $this->putJson("/api/v1/team/prontuario/notes/{$note['id']}", [
            'content_html' => '<p>Paciente com cefaleia tensional; orientada analgesia e retorno em 60 dias.</p>',
        ])->assertOk();

        $alert = $this->postJson('/api/v1/team/prontuario/alerts', [
            'patient_id' => $patient->id,
            'kind' => PatientAlert::KIND_ALERGIA,
            'title' => 'Penicilina',
            'detail' => 'Urticária na infância',
        ])->assertCreated()->json('data');
        $this->assertTrue($alert['active']);

        $this->postJson("/api/v1/team/prontuario/alerts/{$alert['id']}/deactivate", [
            'reason' => 'Teste intradérmico negativo revisado com alergista',
        ])->assertOk()->assertJsonPath('data.active', false);

        $timeline = $this->getJson("/api/v1/team/prontuario?patient_id={$patient->id}")->assertOk()->json('data');
        $this->assertCount(1, $timeline['notes']);
        $this->assertStringContainsString('60 dias', $timeline['notes'][0]['content_html']);
        $this->assertCount(1, $timeline['alerts']);
        $this->assertFalse($timeline['alerts'][0]['active']);
        $this->assertStringContainsString('alergista', (string) $timeline['alerts'][0]['deactivation_reason']);

        $events = ProntuarioEvent::query()->where('patient_id', $patient->id)->pluck('event')->all();
        $this->assertSame(
            ['VISUALIZADO', 'NOTA_CRIADA', 'NOTA_EDITADA', 'ALERTA_INCLUIDO', 'ALERTA_INATIVADO', 'VISUALIZADO'],
            $events,
        );
    }

    public function test_rbac_gates_prontuario_and_ai_is_hidden_without_key(): void
    {
        $doctorUser = User::factory()->create(['role' => UserRole::Doctor]);
        $reception = User::factory()->create(['role' => UserRole::Reception]);
        $this->actingAs($reception);
        $patient = Patient::factory()->create();
        $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);

        $this->getJson("/api/v1/team/prontuario?patient_id={$patient->id}")->assertOk();
        $this->postJson('/api/v1/team/prontuario/notes', [
            'appointment_id' => $appointment->id, 'content_html' => '<p>tentativa</p>',
        ])->assertForbidden();
        $this->postJson('/api/v1/team/prontuario/ai/suggest', [
            'patient_id' => $patient->id, 'question' => 'Qual conduta para cefaleia?',
        ])->assertForbidden();

        $this->actingAs($doctorUser);
        $this->getJson('/api/v1/team/prontuario/ai/status')->assertOk()->assertJsonPath('data.enabled', false);
        $this->postJson('/api/v1/team/prontuario/ai/suggest', [
            'patient_id' => $patient->id, 'question' => 'Qual conduta para cefaleia tensional?',
        ])->assertStatus(503);
    }

    public function test_ai_suggestion_with_key_returns_marked_draft_and_is_audited(): void
    {
        config(['sistema_dez.deepseek_api_key' => 'test-key-123']);
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [['message' => ['content' => '<p>Sugerir analgesia simples e retorno em 30 dias.</p>']]],
            ], 200),
        ]);
        $doctorUser = User::factory()->create(['role' => UserRole::Doctor]);
        $this->actingAs($doctorUser);
        $patient = Patient::factory()->create(['name' => 'Maria IA', 'birth_date' => '1990-01-01']);
        Patient::factory()->create();
        $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);
        PatientAlert::query()->create([
            'patient_id' => $patient->id, 'kind' => PatientAlert::KIND_ALERGIA, 'title' => 'Dipirona', 'created_by' => $doctorUser->id,
        ]);

        $response = $this->postJson('/api/v1/team/prontuario/ai/suggest', [
            'patient_id' => $patient->id, 'appointment_id' => $appointment->id, 'question' => 'Conduta para cefaleia?',
        ])->assertOk()->json('data');

        $this->assertStringContainsString('SUGESTÃO DE IA', $response['suggestion_html']);
        $this->assertStringContainsString('analgesia', $response['suggestion_html']);
        $this->assertSame(1, AiRequest::query()->count());
        $this->assertSame('OK', AiRequest::query()->value('status'));
        $this->assertSame('IA_SOLICITADA', ProntuarioEvent::query()->latest('created_at')->value('event'));

        Http::assertSent(function ($httpRequest) {
            if ($httpRequest->url() !== 'https://api.deepseek.com/chat/completions') {
                return false;
            }
            $data = $httpRequest->data();
            $content = (string) ($data['messages'][1]['content'] ?? '');

            return str_contains($content, 'Dipirona') && str_contains($content, 'Maria IA');
        });
    }
}
