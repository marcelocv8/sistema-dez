<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AttendanceEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_login_records_event_and_relogins_are_flagged(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-07T07:00:00-03:00'));
        $user = User::factory()->create(['password' => 'uma-senha-segura']);
        $this->withHeader('Origin', 'http://localhost:5173');
        $this->get('/sanctum/csrf-cookie')->assertNoContent();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'uma-senha-segura'])->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-09-07T12:30:00-03:00'));
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'uma-senha-segura'])->assertOk();

        $this->assertDatabaseCount('attendance_events', 2);
        $this->assertDatabaseHas('attendance_events', ['user_id' => $user->id, 'service_date' => '2026-09-07']);

        // Relatório via actingAs exige sessão HTTP zerada (o teste anterior usou sessão real).
        $this->flushHeaders();
        $this->actingAs(User::factory()->create(['role' => UserRole::Supervisor]));
        $report = $this->getJson('/api/v1/team/attendance?from=2026-09-07&to=2026-09-07')->assertOk()->json('data');
        $this->assertSame('18:00', $report['exit_hour']);
        $day = $report['days'][0];
        $this->assertSame($user->name, $day['user_name']);
        $this->assertCount(2, $day['logins']);
        $this->assertSame('07:00', $day['first_login']);
        $this->assertSame('12:30', $day['last_login']);
        $this->assertSame(1, $day['relogins']);
        $this->assertFalse($day['relogin_after_exit']);
    }

    public function test_relogin_after_configured_exit_hour_is_flagged(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-07T17:30:00-03:00'));
        $admin = User::factory()->create(['role' => UserRole::Admin, 'password' => 'uma-senha-segura']);
        $this->withHeader('Origin', 'http://localhost:5173');
        $this->get('/sanctum/csrf-cookie')->assertNoContent();
        $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'uma-senha-segura'])->assertOk();

        $this->putJson('/api/v1/team/attendance/exit-hour', ['exit_hour' => '17:00'])->assertOk()->assertJsonPath('data.exit_hour', '17:00');
        $this->travelTo(CarbonImmutable::parse('2026-09-07T18:45:00-03:00'));
        $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'uma-senha-segura'])->assertOk();

        $this->flushHeaders();
        $this->actingAs($admin);
        $day = $this->getJson('/api/v1/team/attendance?from=2026-09-07&to=2026-09-07')->assertOk()->json('data.days.0');
        $this->assertSame('17:00', $day['exit_hour']);
        $this->assertSame(1, $day['relogins']);
        $this->assertTrue($day['relogin_after_exit']);
    }

    public function test_report_is_exclusive_to_admin_and_supervisor_and_csv_exports(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-07T07:00:00-03:00'));
        $reception = User::factory()->create();
        AttendanceEvent::record($reception);

        $this->actingAs(User::factory()->create(['role' => UserRole::Doctor]));
        $this->getJson('/api/v1/team/attendance?from=2026-09-07&to=2026-09-07')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::Manager]));
        $this->getJson('/api/v1/team/attendance?from=2026-09-07&to=2026-09-07')->assertForbidden();
        $this->putJson('/api/v1/team/attendance/exit-hour', ['exit_hour' => '17:30'])->assertForbidden();

        $supervisor = User::factory()->create(['role' => UserRole::Supervisor]);
        $csv = $this->actingAs($supervisor)
            ->getJson('/api/v1/team/attendance/export?from=2026-09-07&to=2026-09-07')->assertOk()->json('data');
        $this->assertStringContainsString('data;usuario', $csv['csv']);
        $this->assertStringContainsString($reception->name, $csv['csv']);

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->getJson('/api/v1/team/attendance?from=2026-09-07&to=2026-09-07')->assertOk();
        $this->putJson('/api/v1/team/attendance/exit-hour', ['exit_hour' => '17:30'])->assertOk();
    }

    public function test_events_are_append_only_and_grouped_by_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-07T07:00:00-03:00'));
        $user = User::factory()->create();
        AttendanceEvent::record($user);
        $this->travelTo(CarbonImmutable::parse('2026-09-08T07:00:00-03:00'));
        AttendanceEvent::record($user);

        $this->actingAs(User::factory()->create(['role' => UserRole::Supervisor]));
        $report = $this->getJson('/api/v1/team/attendance?from=2026-09-01&to=2026-09-30')->assertOk()->json('data');
        $this->assertCount(2, $report['days']);
        $this->assertSame('2026-09-08', $report['days'][0]['date']);
        $this->assertDatabaseCount('attendance_events', 2);
    }
}
