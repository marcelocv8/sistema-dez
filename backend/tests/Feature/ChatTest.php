<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_direct_conversation_reuses_thread_and_messages_flow(): void
    {
        $reception = User::factory()->create(['role' => UserRole::Reception]);
        $doctor = User::factory()->create(['role' => UserRole::Doctor]);
        $this->actingAs($reception);

        $first = $this->postJson('/api/v1/team/chat/conversations', [
            'type' => 'DIRECT', 'participant_ids' => [$doctor->id],
        ])->assertCreated()->json('data');
        $repeat = $this->postJson('/api/v1/team/chat/conversations', [
            'type' => 'DIRECT', 'participant_ids' => [$doctor->id],
        ])->assertCreated()->json('data');
        $this->assertSame($first['id'], $repeat['id']);

        $this->postJson("/api/v1/team/chat/conversations/{$first['id']}/messages", [
            'body' => 'Paciente X chegou, sala 3',
        ])->assertCreated()->assertJsonPath('data.mine', true);

        $this->actingAs($doctor);
        $this->postJson("/api/v1/team/chat/conversations/{$first['id']}/messages", [
            'body' => 'Pode mandar entrar',
        ])->assertCreated();

        $unread = $this->actingAs($reception)->getJson('/api/v1/team/chat/unread')->assertOk()->json('data.unread');
        $this->assertSame(1, $unread);

        $messages = $this->actingAs($reception)->getJson("/api/v1/team/chat/conversations/{$first['id']}/messages")->assertOk()->json('data');
        $this->assertCount(2, $messages);
        $this->assertSame('Paciente X chegou, sala 3', $messages[0]['body']);
        $this->assertTrue($messages[0]['mine']);
        $this->assertFalse($messages[1]['mine']);

        $this->postJson("/api/v1/team/chat/conversations/{$first['id']}/read")->assertOk();
        $this->actingAs($reception);
        $this->assertSame(0, $this->getJson('/api/v1/team/chat/unread')->assertOk()->json('data.unread'));
    }

    public function test_group_conversation_and_participant_isolation(): void
    {
        $reception = User::factory()->create(['role' => UserRole::Reception]);
        $doctor = User::factory()->create(['role' => UserRole::Doctor]);
        $cashier = User::factory()->create(['role' => UserRole::Cashier]);
        $outsider = User::factory()->create(['role' => UserRole::Supervisor]);
        $this->actingAs($reception);

        $group = $this->postJson('/api/v1/team/chat/conversations', [
            'type' => 'GROUP', 'title' => 'Recepção Messejana', 'participant_ids' => [$doctor->id, $cashier->id],
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/team/chat/conversations/{$group['id']}/messages", ['body' => 'Fila com 5 na recepção'])->assertCreated();

        $this->actingAs($outsider);
        $this->getJson("/api/v1/team/chat/conversations/{$group['id']}/messages")->assertForbidden();
        $this->postJson("/api/v1/team/chat/conversations/{$group['id']}/messages", ['body' => 'X'])->assertForbidden();

        $this->actingAs($cashier);
        $list = $this->getJson('/api/v1/team/chat/conversations')->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertSame('Recepção Messejana', $list[0]['title']);
        $this->assertSame(1, $list[0]['unread']);
    }

    public function test_message_can_attach_patient_by_lookup_and_rbac_gates_chat(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Doctor]));
        $this->getJson('/api/v1/team/chat/conversations')->assertOk();
        $this->getJson('/api/v1/team/chat/participants')->assertOk();
        $patient = Patient::factory()->create(['name' => 'Paciente Chat', 'cpf_hash' => hash_hmac('sha256', '11144477735', (string) config('sistema_dez.identity_pepper')), 'cpf_last_four' => '7735']);
        $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);
        $other = User::factory()->create();

        $this->getJson('/api/v1/team/chat/patients/lookup?cpf=11144477735')->assertOk()->assertJsonPath('data.name', 'Paciente Chat');

        $conversation = $this->postJson('/api/v1/team/chat/conversations', [
            'type' => 'DIRECT', 'participant_ids' => [$other->id],
        ])->assertCreated()->json('data');
        $this->postJson("/api/v1/team/chat/conversations/{$conversation['id']}/messages", [
            'body' => 'Exame pronto', 'patient_id' => $patient->id, 'appointment_id' => $appointment->id,
        ])->assertCreated()->assertJsonPath('data.patient.name', 'Paciente Chat');

        $messages = $this->actingAs($other)->getJson("/api/v1/team/chat/conversations/{$conversation['id']}/messages")->assertOk()->json('data');
        $this->assertSame('Exame pronto', $messages[0]['body']);
        $this->assertSame($patient->id, $messages[0]['patient']['id']);
        $this->assertSame(1, count($messages));
    }
}
