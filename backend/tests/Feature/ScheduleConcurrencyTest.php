<?php

namespace Tests\Feature;

use App\Actions\Scheduling\BookAppointment;
use App\Models\Appointment;
use App\Models\CatalogLink;
use App\Models\ServiceOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ScheduleConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_concurrent_requests_for_the_last_slot_create_only_one_appointment(): void
    {
        $this->assertLastSlotRace(false);
    }

    public function test_public_and_reception_compete_safely_for_the_same_last_slot(): void
    {
        $this->assertLastSlotRace(true);
    }

    private function assertLastSlotRace(bool $public): void
    {
        $allowed = ['sistema_dez_test'];
        $override = getenv('E2E_DB_NAME');
        if (is_string($override) && str_starts_with($override, 'sistema_dez_test')) {
            $allowed[] = $override;
        }
        $this->assertContains(config('database.connections.pgsql.database'), $allowed);
        $offering = $public ? ServiceOffering::factory()->published()->create() : ServiceOffering::factory()->create(['online_enabled' => false]);
        $link = $offering->catalogLink ?? CatalogLink::create(['unit_id' => $offering->unit_id, 'procedure_id' => $offering->procedure_id, 'professional_id' => $offering->professional_id, 'agreement_id' => $offering->agreement_id, 'active' => true]);
        $offering->update(['catalog_link_id' => $link->id]);
        $date = CarbonImmutable::tomorrow('America/Fortaleza');
        $offering->scheduleWindows()->create(['effective_from' => $date->toDateString(), 'weekday' => $date->dayOfWeek, 'starts_at' => '08:00', 'ends_at' => '08:30', 'slot_minutes' => 30, 'active' => true]);
        $userId = User::factory()->create()->id;
        $payload = ['offering_id' => $offering->id, 'starts_at' => $date->setTime(8, 0)->toIso8601String(), 'patient' => ['name' => 'Concorrência teste', 'cpf' => '12345678909', 'phone' => '85999999999'], 'privacy_consent' => true];
        $first = [...$payload, 'idempotency_key' => (string) Str::uuid()];
        $second = [...$payload, 'idempotency_key' => (string) Str::uuid()];

        $results = Concurrency::driver('process')->run([
            static function () use ($first, $userId): string {
                try {
                    app(BookAppointment::class)->handle($first, User::findOrFail($userId));

                    return 'created';
                } catch (ValidationException) {
                    return 'full';
                }
            },
            static function () use ($second, $userId, $public): string {
                try {
                    app(BookAppointment::class)->handle($second, $public ? null : User::findOrFail($userId));

                    return 'created';
                } catch (ValidationException) {
                    return 'full';
                }
            },
        ]);

        sort($results);
        $this->assertSame(['created', 'full'], $results);
        $this->assertSame(1, Appointment::where('status', 'CONFIRMED')->count());
    }
}
