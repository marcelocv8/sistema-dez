<?php

namespace Tests\Feature;

use App\Enums\AsoType;
use App\Enums\UserRole;
use App\Models\AgentToken;
use App\Models\Appointment;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\MtAso;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class HermesApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const SECRET = 'hermes-test-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['sistema_dez.management_token_secret' => self::SECRET]);
    }

    public function test_admin_creates_revokes_and_lists_agent_tokens(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        $created = $this->postJson('/api/v1/team/agents', [
            'name' => 'Hermes Agenda',
            'scopes' => ['agenda.read', 'patients.read'],
        ])->assertCreated()->json('data');

        $this->assertStringStartsWith('dez_', $created['token']);
        $this->assertSame(['agenda.read', 'patients.read'], $created['scopes']);
        $this->assertDatabaseCount('agent_tokens', 1);
        $this->assertDatabaseMissing('agent_tokens', ['token_hash' => $created['token']]);

        $this->postJson('/api/v1/team/agents', ['name' => 'X', 'scopes' => ['agenda.write']])->assertUnprocessable();

        $token = AgentToken::firstOrFail();
        $this->postJson('/api/v1/team/agents/'.$token->id.'/revoke')->assertOk();
        $this->assertDatabaseHas('agent_tokens', ['id' => $token->id, 'active' => false]);

        $list = $this->getJson('/api/v1/team/agents')->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertArrayNotHasKey('token', $list[0]);
        $this->assertArrayNotHasKey('token_hash', $list[0]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $this->getJson('/api/v1/team/agents')->assertForbidden();
    }

    public function test_agent_reads_agenda_with_scope_and_is_audited(): void
    {
        $plaintext = $this->createToken(['agenda.read']);
        Appointment::factory()->create(['scheduled_at' => '2026-09-10T12:00:00Z', 'status' => 'CONFIRMED']);

        $response = $this->withToken($plaintext)->getJson('/api/v1/agents/agenda?from=2026-09-01&to=2026-09-30')->assertOk()->json('data');
        $this->assertCount(1, $response['appointments']);
        $this->assertSame('CONFIRMED', $response['appointments'][0]['status']);

        $this->assertDatabaseCount('agent_requests', 1);
        $this->assertDatabaseHas('agent_requests', ['endpoint' => '/api/v1/agents/agenda', 'status_code' => 200]);

        $token = AgentToken::firstOrFail();
        $this->assertNotNull($token->last_used_at);
    }

    public function test_agent_without_scope_is_forbidden_and_invalid_token_is_unauthorized(): void
    {
        $plaintext = $this->createToken(['agenda.read']);

        $this->withToken($plaintext)->getJson('/api/v1/agents/b2b-invoices')->assertForbidden();
        $this->withToken('dez_nao-existe')->getJson('/api/v1/agents/agenda')->assertUnauthorized();
        $this->getJson('/api/v1/agents/agenda')->assertUnauthorized();

        $token = AgentToken::firstOrFail();
        $token->forceFill(['active' => false, 'revoked_at' => now()])->save();
        $this->withToken($plaintext)->getJson('/api/v1/agents/agenda')->assertUnauthorized();
    }

    public function test_agent_reads_patients_production_cash_and_asos_with_scopes(): void
    {
        $plaintext = $this->createToken(['patients.read', 'finance.read', 'mt.read']);

        $this->withToken($plaintext)->getJson('/api/v1/agents/patients?cpf=00000000000')->assertOk()
            ->assertJsonPath('data', null);

        Appointment::factory()->create([
            'scheduled_at' => '2026-09-05T12:00:00Z',
            'status' => 'DONE',
            'payment_status' => 'PAID',
            'paid_at' => '2026-09-05T12:00:00Z',
            'price_snapshot' => '180.00',
        ]);

        MtAso::factory()->emitted()->create(['type' => AsoType::Periodico, 'expires_at' => now()->addDays(20)->toDateString()]);

        $production = $this->withToken($plaintext)->getJson('/api/v1/agents/production')->assertOk()->json('data');
        $this->assertSame('180.00', $production['revenue']);
        $this->assertNotEmpty($production['production_by_doctor']);

        $register = CashRegister::factory()->create();
        $movement = CashMovement::create([
            'cash_register_id' => $register->id, 'type' => 'RECEBIMENTO',
            'payment_method_id' => PaymentMethod::where('code', 'PIX')->firstOrFail()->id,
            'gross_amount' => '180.00', 'fee_amount' => '0.00', 'net_amount' => '180.00',
            'created_by' => $register->opened_by, 'receipt_number' => 1,
        ]);
        $movement->forceFill(['created_at' => '2026-09-05T12:00:00Z'])->save();

        $cash = $this->withToken($plaintext)->getJson('/api/v1/agents/cash-summary?date=2026-09-05')->assertOk()->json('data');
        $this->assertSame('180.00', $cash['revenue']);

        $invoices = $this->withToken($plaintext)->getJson('/api/v1/agents/b2b-invoices')->assertOk()->json('data');
        $this->assertSame([], $invoices['invoices']);

        $asos = $this->withToken($plaintext)->getJson('/api/v1/agents/asos/due?days=30')->assertOk()->json('data');
        $this->assertCount(1, $asos['asos']);
    }

    private function createToken(array $scopes): string
    {
        $plaintext = 'dez_'.bin2hex(random_bytes(24));
        AgentToken::factory()->withScopes($scopes)->create([
            'token_hash' => hash_hmac('sha256', $plaintext, self::SECRET),
        ]);

        return $plaintext;
    }
}
