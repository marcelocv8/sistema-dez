<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AgentToken;
use App\Models\Appointment;
use App\Models\AppointmentConfirmation;
use App\Models\User;
use App\Models\WhatsappOutbox;
use App\Services\RoutineGenerator;
use App\Services\WhatsApp\WhatsAppDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsappIntegrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const SECRET = 'whatsapp-test-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'sistema_dez.management_token_secret' => self::SECRET,
            'sistema_dez.whatsapp.enabled' => true,
            'sistema_dez.whatsapp.api_key' => 'test-api-key',
            'sistema_dez.whatsapp.base_url' => 'http://appchat.test',
            'sistema_dez.whatsapp.reminder_days' => 2,
        ]);
    }

    public function test_event_endpoint_requires_agent_token_with_whatsapp_scope(): void
    {
        $this->postJson('/api/v1/agents/whatsapp/events', [
            'event' => 'mensagem_recebida', 'event_id' => 'x', 'payload' => [],
        ])->assertUnauthorized();

        $this->withToken($this->createToken(['agenda.read']))->postJson('/api/v1/agents/whatsapp/events', [
            'event' => 'mensagem_recebida', 'event_id' => 'mensagem_recebida|wamid.0', 'payload' => [],
        ])->assertForbidden();

        $this->withToken($this->createToken(['whatsapp.events']))->postJson('/api/v1/agents/whatsapp/events', [
            'event' => 'mensagem_recebida',
            'event_id' => 'mensagem_recebida|wamid.1',
            'payload' => ['phone_e164' => '5585999990001', 'texto' => 'bom dia', 'message_key' => 'wamid.1'],
        ])->assertOk();

        $this->assertDatabaseHas('agent_requests', ['endpoint' => '/api/v1/agents/whatsapp/events', 'status_code' => 200]);
        $this->assertDatabaseCount('whatsapp_events', 1);
    }

    public function test_inbound_sim_confirms_pending_d2_and_replay_is_idempotent(): void
    {
        $appointment = $this->appointmentInTwoDays('+5585999990001');
        AppointmentConfirmation::query()->create([
            'appointment_id' => $appointment->id,
            'status' => AppointmentConfirmation::PENDING,
            'requested_on' => today(config('sistema_dez.timezone')),
            'requested_at' => now(),
        ]);

        $this->withToken($this->createToken(['whatsapp.events']))->postJson('/api/v1/agents/whatsapp/events', [
            'event' => 'mensagem_recebida',
            'event_id' => 'mensagem_recebida|wamid.2',
            'payload' => ['phone_e164' => '5585999990001', 'texto' => 'SIM', 'message_key' => 'wamid.2'],
        ])->assertOk()->assertJsonPath('data.result', 'CONFIRMED');

        $this->assertSame(AppointmentConfirmation::CONFIRMED, AppointmentConfirmation::firstOrFail()->status);

        // Replay do mesmo event_id: no-op, sem segundo processamento.
        $this->withToken($this->createToken(['whatsapp.events']))->postJson('/api/v1/agents/whatsapp/events', [
            'event' => 'mensagem_recebida',
            'event_id' => 'mensagem_recebida|wamid.2',
            'payload' => ['phone_e164' => '5585999990001', 'texto' => 'SIM', 'message_key' => 'wamid.2'],
        ])->assertOk()->assertJsonPath('data.duplicate', true);

        $this->assertDatabaseCount('whatsapp_events', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'whatsapp.confirmation_answered']);
    }

    public function test_inbound_declines_and_ignores_unknown_text(): void
    {
        $appointment = $this->appointmentInTwoDays('85999990002');
        AppointmentConfirmation::query()->create([
            'appointment_id' => $appointment->id,
            'status' => AppointmentConfirmation::PENDING,
            'requested_on' => today(config('sistema_dez.timezone')),
            'requested_at' => now(),
        ]);

        $this->withToken($this->createToken(['whatsapp.events']))->postJson('/api/v1/agents/whatsapp/events', [
            'event' => 'mensagem_recebida',
            'event_id' => 'mensagem_recebida|wamid.3',
            'payload' => ['phone_e164' => '5585999990002', 'texto' => 'NÃO, obrigado', 'message_key' => 'wamid.3'],
        ])->assertOk()->assertJsonPath('data.result', 'DECLINED');

        $this->assertSame(AppointmentConfirmation::DECLINED, AppointmentConfirmation::firstOrFail()->status);

        $this->withToken($this->createToken(['whatsapp.events']))->postJson('/api/v1/agents/whatsapp/events', [
            'event' => 'mensagem_recebida',
            'event_id' => 'mensagem_recebida|wamid.4',
            'payload' => ['phone_e164' => '5585999990002', 'texto' => 'que horas devo chegar?', 'message_key' => 'wamid.4'],
        ])->assertOk()->assertJsonPath('data.result', 'IGNORED');

        $this->assertSame(AppointmentConfirmation::DECLINED, AppointmentConfirmation::firstOrFail()->status);
        $this->assertDatabaseCount('whatsapp_events', 2);
    }

    public function test_receipt_status_updates_outbox_monotonically(): void
    {
        $appointment = Appointment::factory()->create();
        $outbox = WhatsappOutbox::query()->create([
            'appointment_id' => $appointment->id,
            'event_key' => WhatsappOutbox::BOOKING,
            'to_phone' => '5585999990009',
            'body' => 'ok',
            'status' => 'SENT',
            'provider_message_key' => 'wamid.9',
            'idempotency_key' => 'booking:'.$appointment->id,
            'sent_at' => now(),
        ]);

        $token = $this->createToken(['whatsapp.events']);
        $this->withToken($token)->postJson('/api/v1/agents/whatsapp/events', [
            'event' => 'mensagem_status',
            'event_id' => 'mensagem_status|wamid.9|entregue',
            'payload' => ['message_key' => 'wamid.9', 'status' => 'entregue'],
        ])->assertOk()->assertJsonPath('data.result', 'STATUS');
        $this->assertSame('DELIVERED', $outbox->fresh()->status);

        $this->withToken($token)->postJson('/api/v1/agents/whatsapp/events', [
            'event' => 'mensagem_status',
            'event_id' => 'mensagem_status|wamid.9|lida',
            'payload' => ['message_key' => 'wamid.9', 'status' => 'lida'],
        ])->assertOk();
        $this->assertSame('READ', $outbox->fresh()->status);

        $this->withToken($token)->postJson('/api/v1/agents/whatsapp/events', [
            'event' => 'mensagem_status',
            'event_id' => 'mensagem_status|wamid.9|falha',
            'payload' => ['message_key' => 'wamid.9', 'status' => 'falha'],
        ])->assertOk()->assertJsonPath('data.result', 'STATUS');
        $this->assertSame('READ', $outbox->fresh()->status); // receipt não regride
    }

    public function test_booking_message_dispatches_once_with_idempotency(): void
    {
        Http::fake([
            'appchat.test/integracoes/mensagens' => Http::response(['data' => ['message_key' => 'wamid.10', 'replay' => false]], 201),
        ]);

        $appointment = Appointment::factory()->create();
        $appointment->patient->update(['phone' => '(85) 99999-0002', 'name' => 'Simulado da Silva']);

        app(WhatsAppDispatcher::class)->bookingMessage($appointment);
        $outbox = WhatsappOutbox::firstOrFail();
        $this->assertSame('SENT', $outbox->status);
        $this->assertSame('wamid.10', $outbox->provider_message_key);
        $this->assertSame('booking:'.$appointment->id, $outbox->idempotency_key);
        $this->assertStringContainsString('Simulado', $outbox->body);

        app(WhatsAppDispatcher::class)->bookingMessage($appointment);
        $this->assertDatabaseCount('whatsapp_outbox', 1);
    }

    public function test_booking_message_skips_patients_without_phone(): void
    {
        Http::fake();
        $appointment = Appointment::factory()->create();
        $appointment->patient->update(['phone' => null]);

        app(WhatsAppDispatcher::class)->bookingMessage($appointment);

        $this->assertDatabaseCount('whatsapp_outbox', 0);
        Http::assertNothingSent();
    }

    public function test_disabled_flag_keeps_everything_inert(): void
    {
        config(['sistema_dez.whatsapp.enabled' => false]);
        Http::fake();

        $appointment = Appointment::factory()->create();
        app(WhatsAppDispatcher::class)->bookingMessage($appointment);
        $this->artisan('whatsapp:dispatch-confirmations')->assertExitCode(0);

        $this->assertDatabaseCount('whatsapp_outbox', 0);
        $this->assertDatabaseCount('appointment_confirmations', 0);
        Http::assertNothingSent();
    }

    public function test_command_sends_d2_idempotently_and_skips_missing_phone(): void
    {
        Http::fake([
            'appchat.test/integracoes/mensagens' => Http::response(['data' => ['message_key' => 'wamid.20', 'replay' => false]], 201),
        ]);

        $appointment = $this->appointmentInTwoDays('85999990003');
        $this->artisan('whatsapp:dispatch-confirmations')->assertExitCode(0);

        $this->assertDatabaseCount('whatsapp_outbox', 1);
        $this->assertDatabaseHas('appointment_confirmations', [
            'appointment_id' => $appointment->id,
            'status' => AppointmentConfirmation::PENDING,
        ]);
        Http::assertSentCount(1);

        $this->artisan('whatsapp:dispatch-confirmations')->assertExitCode(0);
        $this->assertDatabaseCount('appointment_confirmations', 1);
        $this->assertDatabaseCount('whatsapp_outbox', 1);

        $noPhone = $this->appointmentInTwoDays(null, '09:30');
        $noPhone->patient->update(['phone' => null]);
        $this->artisan('whatsapp:dispatch-confirmations')->assertExitCode(0);
        $this->assertDatabaseCount('whatsapp_outbox', 1);
        $this->assertDatabaseCount('appointment_confirmations', 1);
    }

    public function test_command_reprocesses_failed_outbox_next_day(): void
    {
        $appointment = $this->appointmentInTwoDays('85999990004');
        Http::fake([
            'appchat.test/integracoes/mensagens' => Http::sequence()
                ->push(['error' => 'Nenhuma linha WhatsApp conectada'], 409)
                ->push(['data' => ['message_key' => 'wamid.21', 'replay' => false]], 201),
        ]);

        $this->artisan('whatsapp:dispatch-confirmations')->assertExitCode(0);
        $this->assertDatabaseHas('whatsapp_outbox', ['status' => 'FAILED']);

        // Dia seguinte: o comando reprocessa a outbox FAILED com a mesma chave.
        config(['sistema_dez.whatsapp.reminder_days' => 2]);
        $this->travel(1)->days();
        $this->artisan('whatsapp:dispatch-confirmations')->assertExitCode(0);
        $this->travelBack();

        $outbox = WhatsappOutbox::firstOrFail();
        $this->assertSame('SENT', $outbox->status);
        $this->assertSame('wamid.21', $outbox->provider_message_key);
        $this->assertDatabaseCount('whatsapp_outbox', 1);
    }

    public function test_routine_second_layer_skips_answered_confirmations(): void
    {
        $confirmed = $this->appointmentTomorrow('85999990005', '09:00');
        AppointmentConfirmation::query()->create([
            'appointment_id' => $confirmed->id,
            'status' => AppointmentConfirmation::CONFIRMED,
            'requested_on' => today(config('sistema_dez.timezone')),
            'requested_at' => now(),
        ]);

        app(RoutineGenerator::class)->generate(CarbonImmutable::today(config('sistema_dez.timezone')));
        $this->assertDatabaseMissing('routine_tasks', ['type' => 'CONFIRMACAO', 'ref_id' => $confirmed->id]);

        $pending = $this->appointmentTomorrow('85999990006', '10:00');
        AppointmentConfirmation::query()->create([
            'appointment_id' => $pending->id,
            'status' => AppointmentConfirmation::PENDING,
            'requested_on' => today(config('sistema_dez.timezone')),
            'requested_at' => now(),
        ]);

        app(RoutineGenerator::class)->generate(CarbonImmutable::today(config('sistema_dez.timezone')));
        $this->assertDatabaseHas('routine_tasks', ['type' => 'CONFIRMACAO', 'ref_id' => $pending->id]);

        // Integração desligada: comportamento original (tudo vira ligação).
        config(['sistema_dez.whatsapp.enabled' => false]);
        app(RoutineGenerator::class)->generate(CarbonImmutable::today(config('sistema_dez.timezone')));
        $this->assertDatabaseHas('routine_tasks', ['type' => 'CONFIRMACAO', 'ref_id' => $confirmed->id]);
    }

    public function test_lines_endpoint_lists_and_selects_line(): void
    {
        Http::fake([
            'appchat.test/integracoes/conexoes' => Http::response([
                'conexoes' => [['id' => 7, 'numero' => '5585888800010', 'status_saude' => 'conectado', 'provedor' => 'baileys']],
            ], 200),
        ]);

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->getJson('/api/v1/team/whatsapp/lines')->assertOk()
            ->assertJsonPath('data.lines.0.id', 7)
            ->assertJsonPath('data.selected', null);

        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]))
            ->getJson('/api/v1/team/whatsapp/lines')->assertForbidden();

        $this->actingAs($admin)->putJson('/api/v1/team/whatsapp/lines', ['conexao_id' => 7])->assertOk();
        $this->assertDatabaseHas('app_settings', ['key' => 'whatsapp_conexao_id', 'value' => '7']);
        $this->actingAs($admin)->getJson('/api/v1/team/whatsapp/lines')->assertOk()->assertJsonPath('data.selected', 7);
    }

    public function test_lines_endpoint_fails_open_and_reports_disabled(): void
    {
        Http::fake([
            'appchat.test/integracoes/conexoes' => Http::response([], 500),
        ]);

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->getJson('/api/v1/team/whatsapp/lines')->assertOk()
            ->assertJsonPath('data.lines', [])
            ->assertJsonPath('data.error', fn (?string $error) => $error !== null);

        config(['sistema_dez.whatsapp.enabled' => false]);
        $this->actingAs($admin)->getJson('/api/v1/team/whatsapp/lines')->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.lines', []);
    }

    private function createToken(array $scopes): string
    {
        $plaintext = 'dez_'.bin2hex(random_bytes(24));
        AgentToken::factory()->withScopes($scopes)->create([
            'token_hash' => hash_hmac('sha256', $plaintext, self::SECRET),
        ]);

        return $plaintext;
    }

    /** Agendamento CONFIRMED em D+2 (fuso da clínica) com unidade operacional. */
    private function appointmentInTwoDays(?string $phone, string $time = '08:00'): Appointment
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));
        $appointment = Appointment::factory()->create([
            'scheduled_at' => now()->addDays(2)->setTime($hour, $minute),
        ]);
        $appointment->serviceOffering->unit->update(['operational_enabled' => true]);
        if ($phone !== null) {
            $appointment->patient->update(['phone' => $phone]);
        }

        return $appointment->refresh();
    }

    private function appointmentTomorrow(string $phone, string $time): Appointment
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));
        $appointment = Appointment::factory()->create([
            'scheduled_at' => now()->addDay()->setTime($hour, $minute),
        ]);
        $appointment->serviceOffering->unit->update(['operational_enabled' => true]);
        $appointment->patient->update(['phone' => $phone]);

        return $appointment->refresh();
    }
}
