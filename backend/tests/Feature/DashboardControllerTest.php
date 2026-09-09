<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\QueueEntry;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_patient_flow_and_queue_for_today(): void
    {
        $user = User::factory()->create(['role' => UserRole::Reception]);
        $unit = Unit::factory()->create();
        $today = CarbonImmutable::today((string) config('sistema_dez.timezone'));
        $appointment = Appointment::factory()->create(['scheduled_at' => $today->utc()->setTime(9, 0)]);
        $patient = $appointment->patient;
        $offering = $appointment->serviceOffering;

        QueueEntry::factory()->createMany([
            ['unit_id' => $unit->id, 'service_offering_id' => $offering->id, 'service_date' => $today->toDateString(), 'checked_in_at' => $today->utc()->setTime(8, 0), 'original_sequence' => 1, 'status' => 'WAITING', 'appointment_id' => $appointment->id],
            ['unit_id' => $unit->id, 'service_offering_id' => $offering->id, 'service_date' => $today->toDateString(), 'checked_in_at' => $today->utc()->setTime(8, 5), 'original_sequence' => 2, 'status' => 'IN_SERVICE'],
            ['unit_id' => $unit->id, 'service_offering_id' => $offering->id, 'service_date' => $today->toDateString(), 'checked_in_at' => $today->utc()->setTime(8, 10), 'original_sequence' => 3, 'status' => 'FINISHED'],
        ]);
        QueueEntry::factory()->create([
            'unit_id' => $unit->id, 'service_offering_id' => $offering->id, 'service_date' => $today->subDay()->toDateString(),
            'checked_in_at' => $today->subDay()->utc()->setTime(8, 0), 'original_sequence' => 4, 'status' => 'WAITING',
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.patient_flow.waiting', 1)
            ->assertJsonPath('data.patient_flow.in_care', 1)
            ->assertJsonPath('data.patient_flow.finished', 1)
            ->assertJsonPath('data.patient_flow.checkout', 0)
            ->assertJsonPath('data.financials_available', true)
            ->assertJsonPath('data.revenue', '0.00')
            ->assertJsonCount(3, 'data.queue')
            ->assertJsonPath('data.queue.0.patient_name', $patient->name);
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
    }
}
