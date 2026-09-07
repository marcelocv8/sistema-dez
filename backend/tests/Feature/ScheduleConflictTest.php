<?php

namespace Tests\Feature;

use App\Actions\Scheduling\MaterializeScheduleOccurrences;
use App\Enums\UserRole;
use App\Models\CatalogLink;
use App\Models\ProcedurePrice;
use App\Models\Professional;
use App\Models\ServiceOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScheduleConflictTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_narrowing_window_warns_and_preserves_bookings(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T07:00:00-03:00'));
        $offering = $this->offering();
        $window = $offering->scheduleWindows()->firstOrFail();
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $appointment = $this->book($offering, '2026-09-04T09:00:00-03:00', '11144477735');

        // Encolher o fim da janela para 08:30 afeta a ficha das 09:00: avisa, sem apagar.
        $this->actingAs(User::factory()->create(['role' => UserRole::Supervisor]));
        $response = $this->putJson("/api/v1/team/service-offerings/{$offering->id}/schedule-windows/{$window->id}", [
            'effective_from' => '2026-09-01', 'effective_to' => null, 'weekday' => 5,
            'starts_at' => '08:00', 'ends_at' => '08:30', 'slot_minutes' => 30, 'active' => true,
        ])->assertOk();
        $this->assertCount(1, $response->json('conflicts'));
        $this->assertSame($appointment, $response->json('conflicts.0.appointment_id'));
        $this->assertNotNull($response->json('conflict_id'));
        $this->assertDatabaseHas('appointments', ['id' => $appointment, 'status' => 'CONFIRMED']);
        $this->assertDatabaseHas('schedule_conflicts', ['service_offering_id' => $offering->id, 'status' => 'OPEN']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'schedule.conflict_opened']);

        // Ampliar de volta não gera nova pendência.
        $quiet = $this->putJson("/api/v1/team/service-offerings/{$offering->id}/schedule-windows/{$window->id}", [
            'effective_from' => '2026-09-01', 'effective_to' => null, 'weekday' => 5,
            'starts_at' => '08:00', 'ends_at' => '10:00', 'slot_minutes' => 30, 'active' => true,
        ])->assertOk();
        $this->assertCount(0, $quiet->json('conflicts'));

        // Resolver a pendência com motivo.
        $conflictId = $response->json('conflict_id');
        $this->postJson("/api/v1/team/schedule-conflicts/{$conflictId}/resolve", ['resolution_note' => 'Remarcado com o paciente por telefone'])
            ->assertOk()->assertJsonPath('data.status', 'RESOLVED');
        $this->getJson('/api/v1/team/schedule-conflicts?status=OPEN')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_blocking_day_with_bookings_opens_conflict(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T07:00:00-03:00'));
        $offering = $this->offering();
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $appointment = $this->book($offering, '2026-09-04T08:00:00-03:00', '11144477735');

        $this->actingAs(User::factory()->create(['role' => UserRole::Supervisor]));
        $response = $this->postJson("/api/v1/team/service-offerings/{$offering->id}/schedule-exceptions", [
            'date' => '2026-09-04', 'reason' => 'Feriado municipal', 'blocked' => true,
        ])->assertCreated();
        $this->assertCount(1, $response->json('conflicts'));
        $this->assertSame($appointment, $response->json('conflicts.0.appointment_id'));
    }

    public function test_window_import_dry_run_and_commit_with_overlap_report(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T07:00:00-03:00'));
        $offering = $this->offering();
        $this->actingAs(User::factory()->create(['role' => UserRole::Supervisor]));

        $rows = [
            ['effective_from' => '2026-09-07', 'effective_to' => null, 'weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '12:00', 'slot_minutes' => 30],
            ['effective_from' => '2026-09-07', 'effective_to' => null, 'weekday' => 3, 'starts_at' => '13:00', 'ends_at' => '17:00', 'slot_minutes' => 30],
        ];
        $dry = $this->postJson("/api/v1/team/service-offerings/{$offering->id}/schedule-windows/import", [
            'dry_run' => true, 'rows' => $rows,
        ])->assertCreated()->json('data');
        $this->assertTrue($dry['dry_run']);
        $this->assertSame(2, $dry['validated']);
        $this->assertSame(0, $dry['created']);
        $this->assertSame(1, $offering->scheduleWindows()->count());

        $commit = $this->postJson("/api/v1/team/service-offerings/{$offering->id}/schedule-windows/import", [
            'rows' => $rows,
        ])->assertCreated()->json('data');
        $this->assertSame(2, $commit['created']);
        $this->assertSame(3, $offering->scheduleWindows()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'schedule.imported']);

        // Sobreposição com o existente: nada persiste.
        $this->postJson("/api/v1/team/service-offerings/{$offering->id}/schedule-windows/import", [
            'rows' => [['effective_from' => '2026-09-01', 'effective_to' => null, 'weekday' => 5, 'starts_at' => '09:00', 'ends_at' => '11:00']],
        ])->assertUnprocessable();
        $this->assertSame(3, $offering->scheduleWindows()->count());

        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $this->postJson("/api/v1/team/service-offerings/{$offering->id}/schedule-windows/import", ['rows' => $rows])->assertForbidden();
    }

    public function test_walk_in_availability_reports_blocked_periods(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T07:00:00-03:00'));
        $offering = $this->offering('WALK_IN_INFO');
        $this->actingAs(User::factory()->create(['role' => UserRole::Supervisor]));
        $this->postJson("/api/v1/team/service-offerings/{$offering->id}/schedule-exceptions", [
            'date' => '2026-09-04', 'starts_at' => '09:00', 'ends_at' => '10:00', 'reason' => 'Intervalo da equipe', 'blocked' => true,
        ])->assertCreated();

        $rows = $this->getJson("/api/v1/team/availability?offering_id={$offering->id}&from=2026-09-04&to=2026-09-04")
            ->assertOk()->json('data');
        $this->assertNotEmpty($rows);
        $this->assertSame([], $rows[0]['slots']);
        $this->assertCount(1, $rows[0]['blocked_periods']);
        $this->assertSame('Intervalo da equipe', $rows[0]['blocked_periods'][0]['reason']);
    }

    private function offering(string $mode = 'FIXED_SLOT'): ServiceOffering
    {
        $price = ProcedurePrice::factory()->create(['amount' => '150.00']);
        $price->unit->update(['operational_enabled' => true]);
        $price->agreement->update(['is_default' => true, 'requires_authorization' => false]);
        $professional = Professional::factory()->create();
        $link = CatalogLink::create(['unit_id' => $price->unit_id, 'procedure_id' => $price->procedure_id,
            'agreement_id' => $price->agreement_id, 'professional_id' => $professional->id, 'active' => true]);
        $offering = ServiceOffering::factory()->create([
            'unit_id' => $link->unit_id, 'procedure_id' => $link->procedure_id, 'professional_id' => $link->professional_id,
            'agreement_id' => $link->agreement_id, 'catalog_link_id' => $link->id, 'procedure_price_id' => $price->id,
            'price' => '150.00', 'booking_mode' => $mode, 'modality' => 'PRESENTIAL',
            'duration_override_minutes' => 30, 'active' => true, 'online_enabled' => false,
        ]);
        $offering->scheduleWindows()->create(['effective_from' => '2026-09-01', 'weekday' => 5,
            'starts_at' => '08:00:00', 'ends_at' => '10:00:00', 'slot_minutes' => 30, 'active' => true]);
        app(MaterializeScheduleOccurrences::class)->handle($offering);

        return $offering;
    }

    private function book(ServiceOffering $offering, string $startsAt, string $cpf): string
    {
        return $this->postJson('/api/v1/team/appointments', [
            'idempotency_key' => (string) Str::uuid(), 'offering_id' => $offering->id, 'starts_at' => $startsAt,
            'patient' => ['name' => 'Paciente Escala', 'cpf' => $cpf, 'phone' => '85999999999', 'birth_date' => '1990-01-01'],
            'privacy_consent' => true,
        ])->assertCreated()->json('data.id');
    }
}
