<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_team_user_can_login_read_identity_and_logout(): void
    {
        $this->withHeader('Origin', 'http://localhost:5173');

        $user = User::factory()->create([
            'email' => 'recepcao@dez.test',
            'password' => 'uma-senha-segura',
            'role' => UserRole::Reception,
        ]);

        $this->get('/sanctum/csrf-cookie')->assertNoContent();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'recepcao@dez.test',
            'password' => 'uma-senha-segura',
        ])->assertOk()->assertJsonPath('data.role', 'RECEPTION')
            ->assertJsonPath('data.permissions.0', 'prices.view');

        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'inativo@dez.test',
            'password' => 'uma-senha-segura',
            'active' => false,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'inativo@dez.test',
            'password' => 'uma-senha-segura',
        ])->assertUnprocessable()->assertJsonPath('message', 'As credenciais informadas são inválidas.');
    }

    public function test_unauthenticated_api_request_without_json_accept_header_returns_unauthorized(): void
    {
        $this->get('/api/v1/me')->assertUnauthorized();
        $this->get('/api/v1/team/prices?q=consulta')->assertUnauthorized();
    }

    public function test_login_is_rate_limited(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'ausente@dez.test',
                'password' => 'senha-incorreta',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ausente@dez.test',
            'password' => 'senha-incorreta',
        ])->assertTooManyRequests();
    }
}
