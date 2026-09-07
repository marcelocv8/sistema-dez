<?php

namespace Tests\Feature;

use App\Actions\Scheduling\MaterializeScheduleOccurrences;
use App\Enums\UserRole;
use App\Models\CatalogLink;
use App\Models\ProcedurePrice;
use App\Models\Professional;
use App\Models\QueueEntry;
use App\Models\ServiceOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DisplayTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_display_shows_current_call_and_next_without_sensitive_data(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T07:00:00-03:00'));
        $offering = $this->offering();
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $first = $this->book($offering, '2026-09-04T08:00:00-03:00', 'Maria Aparecida Silva', '11144477735');
        $second = $this->book($offering, '2026-09-04T08:30:00-03:00', 'João Pedro Lima', '12345678909');
        $this->postJson("/api/v1/team/appointments/{$first}/check-in")->assertOk();
        $this->postJson("/api/v1/team/appointments/{$second}/check-in")->assertOk();
        $entry = QueueEntry::query()->where('appointment_id', $first)->firstOrFail();

        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]));
        $this->postJson("/api/v1/team/queue/{$entry->id}/call")->assertOk();

        // Rota pública: sem sessão.
        auth()->forgetGuards();
        $payload = $this->getJson("/api/v1/public/display?unit_id={$offering->unit_id}")->assertOk()->json('data');

        $this->assertSame('A001', $payload['current']['ticket']);
        $this->assertSame('Maria', $payload['current']['first_name']);
        $this->assertArrayNotHasKey('patient_name', $payload['current']);
        $this->assertArrayNotHasKey('cpf', $payload['current']);
        $this->assertStringNotContainsString('Aparecida', (string) json_encode($payload));
        $this->assertCount(1, $payload['next']);
        $this->assertSame('A002', $payload['next'][0]['ticket']);
        $this->assertSame('João', $payload['next'][0]['first_name']);

        $units = $this->getJson('/api/v1/public/units')->assertOk()->json('data');
        $this->assertNotEmpty($units);
        $this->assertArrayNotHasKey('cnpj', $units[0]);

        $otherDay = $this->getJson("/api/v1/public/display?unit_id={$offering->unit_id}&date=2026-09-05")->assertOk()->json('data');
        $this->assertNull($otherDay['current']);
        $this->assertSame([], $otherDay['next']);
    }

    public function test_display_rejects_unknown_or_inactive_unit(): void
    {
        $this->getJson('/api/v1/public/display?unit_id='.((string) Str::uuid()))->assertUnprocessable();
        $this->getJson('/api/v1/public/display')->assertUnprocessable();

        $offering = $this->offering();
        $offering->unit->update(['operational_enabled' => false]);
        $this->getJson("/api/v1/public/display?unit_id={$offering->unit_id}")->assertUnprocessable();
    }

    private function offering(): ServiceOffering
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
            'price' => '150.00', 'booking_mode' => 'FIXED_SLOT', 'modality' => 'PRESENTIAL',
            'duration_override_minutes' => 30, 'active' => true, 'online_enabled' => false,
        ]);
        $offering->scheduleWindows()->create(['effective_from' => '2026-09-01', 'weekday' => 5,
            'starts_at' => '08:00:00', 'ends_at' => '10:00:00', 'slot_minutes' => 30, 'active' => true]);
        app(MaterializeScheduleOccurrences::class)->handle($offering);

        return $offering;
    }

    private function book(ServiceOffering $offering, string $startsAt, string $name, string $cpf): string
    {
        return $this->postJson('/api/v1/team/appointments', [
            'idempotency_key' => (string) Str::uuid(), 'offering_id' => $offering->id, 'starts_at' => $startsAt,
            'patient' => ['name' => $name, 'cpf' => $cpf, 'phone' => '85999999999', 'birth_date' => '1990-01-01'],
            'privacy_consent' => true,
        ])->assertCreated()->json('data.id');
    }
}
