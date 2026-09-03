<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Agreement;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\Professional;
use App\Models\ServiceOffering;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamAppointmentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_appointments_for_a_date_with_relations(): void
    {
        $user = User::factory()->create(['role' => UserRole::Reception]);
        $professional = Professional::factory()->create(['name' => 'Dra. Ana Clara']);
        $unit = Unit::factory()->create();
        $agreement = Agreement::factory()->create();
        $procedure = Procedure::factory()->create(['name' => 'Consulta Cardiológica', 'group_name' => 'CARDIOLOGIA', 'duration_minutes' => 40, 'active' => true]);
        $offering = ServiceOffering::factory()->create([
            'unit_id' => $unit->id,
            'procedure_id' => $procedure->id,
            'professional_id' => $professional->id,
            'agreement_id' => $agreement->id,
            'duration_override_minutes' => null,
        ]);
        $patient = Patient::factory()->create(['name' => 'Maria da Silva']);
        Appointment::factory()->create([
            'patient_id' => $patient->id,
            'service_offering_id' => $offering->id,
            'scheduled_at' => '2020-01-10 09:00:00',
            'status' => 'CONFIRMED',
            'price_snapshot' => 150,
        ]);
        Appointment::factory()->create([
            'service_offering_id' => $offering->id,
            'scheduled_at' => '2020-01-11 10:00:00',
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/team/appointments?date=2020-01-10')
            ->assertOk()
            ->assertJsonPath('data.date', '2020-01-10')
            ->assertJsonCount(1, 'data.appointments')
            ->assertJsonPath('data.appointments.0.patient_name', 'Maria da Silva')
            ->assertJsonPath('data.appointments.0.professional.name', 'Dra. Ana Clara')
            ->assertJsonPath('data.appointments.0.procedure.name', 'Consulta Cardiológica')
            ->assertJsonPath('data.appointments.0.procedure.group_name', 'CARDIOLOGIA')
            ->assertJsonPath('data.appointments.0.status', 'CONFIRMED')
            ->assertJsonPath('data.appointments.0.price', '150.00');

        $this->actingAs($user)
            ->getJson('/api/v1/team/appointments')
            ->assertOk()
            ->assertJsonCount(0, 'data.appointments');
    }

    public function test_filters_by_professional_and_requires_permission(): void
    {
        $user = User::factory()->create(['role' => UserRole::Reception]);
        $offeringA = ServiceOffering::factory()->create(['professional_id' => Professional::factory()->create(['name' => 'Dr. A'])->id]);
        $offeringB = ServiceOffering::factory()->create(['professional_id' => Professional::factory()->create(['name' => 'Dr. B'])->id]);
        Appointment::factory()->create(['service_offering_id' => $offeringA->id, 'scheduled_at' => today()->setTime(9, 0)]);
        Appointment::factory()->create(['service_offering_id' => $offeringB->id, 'scheduled_at' => today()->setTime(9, 30)]);

        $this->actingAs($user)
            ->getJson('/api/v1/team/appointments?professional_id='.$offeringA->professional_id)
            ->assertOk()
            ->assertJsonCount(1, 'data.appointments')
            ->assertJsonPath('data.appointments.0.professional.name', 'Dr. A');

        $this->actingAs(User::factory()->create(['active' => false])->fresh())
            ->getJson('/api/v1/team/appointments')
            ->assertForbidden();
    }
}
