<?php

namespace Tests\Feature;

use App\Enums\BookingMode;
use App\Enums\Modality;
use App\Models\Agreement;
use App\Models\Appointment;
use App\Models\Procedure;
use App\Models\ScheduleWindow;
use App\Models\ServiceOffering;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PublicAvailabilityControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_generates_fixed_slots_from_the_effective_schedule(): void
    {
        [$offering, $query] = $this->offeringWithWindow();

        $this->getJson(route('api.v1.public.availability.index', $query))
            ->assertOk()
            ->assertJsonPath('data.0.offering_id', $offering->id)
            ->assertJsonPath('data.0.booking_mode', BookingMode::FixedSlot->value)
            ->assertJsonPath('data.0.slots.0.starts_at', '2026-08-31T08:00:00-03:00')
            ->assertJsonPath('data.0.slots.0.remaining_capacity', 1)
            ->assertJsonCount(4, 'data.0.slots');
    }

    public function test_arrival_blocks_offer_only_the_first_block_with_remaining_capacity(): void
    {
        [$offering, $query] = $this->offeringWithWindow(BookingMode::ArrivalBlock);
        foreach (range(1, 3) as $number) {
            Appointment::factory()->for($offering)->create([
                'scheduled_at' => CarbonImmutable::parse('2026-08-31 08:00:00', 'America/Fortaleza')->utc(),
                'recommended_arrival_at' => CarbonImmutable::parse('2026-08-31 08:00:00', 'America/Fortaleza')->utc(),
            ]);
        }

        $this->getJson(route('api.v1.public.availability.index', $query))
            ->assertOk()
            ->assertJsonCount(1, 'data.0.slots')
            ->assertJsonPath('data.0.slots.0.starts_at', '2026-08-31T09:00:00-03:00')
            ->assertJsonPath('data.0.slots.0.remaining_capacity', 3);
    }

    public function test_walk_in_service_returns_hours_without_reservable_slots(): void
    {
        [, $query] = $this->offeringWithWindow(BookingMode::WalkInInfo);

        $this->getJson(route('api.v1.public.availability.index', $query))
            ->assertOk()
            ->assertJsonPath('data.0.booking_mode', BookingMode::WalkInInfo->value)
            ->assertJsonPath('data.0.operating_window.starts_at', '2026-08-31T08:00:00-03:00')
            ->assertJsonCount(0, 'data.0.slots');
    }

    public function test_it_rejects_periods_longer_than_thirty_days(): void
    {
        [, $query] = $this->offeringWithWindow();
        $query['to'] = '2026-09-30';

        $this->getJson(route('api.v1.public.availability.index', $query))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    }

    /** @return array{ServiceOffering, array<string, string>} */
    private function offeringWithWindow(BookingMode $mode = BookingMode::FixedSlot): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-31T07:00:00-03:00'));
        $unit = Unit::factory()->create();
        $agreement = Agreement::factory()->create();
        $procedure = Procedure::factory()->create(['duration_minutes' => 30]);
        $offering = ServiceOffering::factory()->published()->for($unit)->for($agreement)->for($procedure)->create([
            'booking_mode' => $mode,
            'modality' => Modality::Presential,
            'arrival_block_minutes' => 60,
            'arrival_block_capacity' => 3,
        ]);
        ScheduleWindow::factory()->for($offering)->create([
            'effective_from' => '2026-01-01',
            'weekday' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '10:00:00',
            'slot_minutes' => 30,
        ]);

        return [$offering, [
            'procedure_id' => $procedure->id,
            'unit_id' => $unit->id,
            'agreement_id' => $agreement->id,
            'modality' => Modality::Presential->value,
            'from' => '2026-08-31',
            'to' => '2026-08-31',
        ]];
    }
}
