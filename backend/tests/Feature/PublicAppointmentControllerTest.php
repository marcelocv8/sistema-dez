<?php

namespace Tests\Feature;

use App\Enums\Modality;
use App\Models\Agreement;
use App\Models\Procedure;
use App\Models\ScheduleWindow;
use App\Models\ServiceOffering;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicAppointmentControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_books_an_available_slot_without_storing_the_raw_cpf(): void
    {
        $offering = $this->availableOffering();
        $payload = $this->payload($offering);

        $response = $this->postJson(route('api.v1.public.appointments.store'), $payload);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'CONFIRMED')
            ->assertJsonPath('data.scheduled_at', '2026-08-31T08:00:00-03:00')
            ->assertJsonPath('data.service.offering_id', $offering->id)
            ->assertJsonStructure(['data' => ['id', 'management_token']]);
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseHas('patients', ['cpf_last_four' => '8909']);
        $this->assertDatabaseMissing('patients', ['cpf_hash' => '12345678909']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'appointment.created']);
    }

    public function test_repeating_an_idempotency_key_does_not_duplicate_the_booking(): void
    {
        $offering = $this->availableOffering();
        $payload = $this->payload($offering);

        $firstToken = $this->postJson(route('api.v1.public.appointments.store'), $payload)->json('data.management_token');
        $secondToken = $this->postJson(route('api.v1.public.appointments.store'), $payload)
            ->assertCreated()
            ->json('data.management_token');

        $this->assertSame($firstToken, $secondToken);
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_it_rejects_reusing_an_idempotency_key_with_different_data(): void
    {
        $offering = $this->availableOffering();
        $payload = $this->payload($offering);
        $this->postJson(route('api.v1.public.appointments.store'), $payload)->assertCreated();
        $payload['starts_at'] = '2026-08-31T08:30:00-03:00';

        $this->postJson(route('api.v1.public.appointments.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_it_rejects_a_slot_that_is_already_full(): void
    {
        $offering = $this->availableOffering();
        $this->postJson(route('api.v1.public.appointments.store'), $this->payload($offering))->assertCreated();

        $payload = $this->payload($offering);
        $payload['idempotency_key'] = (string) Str::uuid();
        $payload['patient']['cpf'] = '98765432100';

        $this->postJson(route('api.v1.public.appointments.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
        $this->assertDatabaseCount('appointments', 1);
    }

    private function availableOffering(): ServiceOffering
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-31T07:00:00-03:00'));
        $offering = ServiceOffering::factory()->published()
            ->for(Unit::factory())
            ->for(Agreement::factory())
            ->for(Procedure::factory())
            ->create(['modality' => Modality::Presential]);
        ScheduleWindow::factory()->for($offering)->create([
            'effective_from' => '2026-01-01',
            'weekday' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '10:00:00',
        ]);

        return $offering;
    }

    /** @return array<string, mixed> */
    private function payload(ServiceOffering $offering): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'offering_id' => $offering->id,
            'starts_at' => '2026-08-31T08:00:00-03:00',
            'patient' => [
                'name' => 'Maria da Silva',
                'cpf' => '123.456.789-09',
                'birth_date' => '1990-01-01',
                'phone' => '85999999999',
                'email' => 'maria@example.com',
            ],
            'privacy_consent' => true,
        ];
    }
}
