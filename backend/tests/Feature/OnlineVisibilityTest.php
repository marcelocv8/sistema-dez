<?php

namespace Tests\Feature;

use App\Actions\Scheduling\MaterializeScheduleOccurrences;
use App\Actions\Scheduling\RefreshOfferingVisibility;
use App\Enums\UserRole;
use App\Models\CatalogLink;
use App\Models\ProcedurePrice;
use App\Models\Professional;
use App\Models\ServiceOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class OnlineVisibilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_created_offering_with_scale_goes_online_automatically_and_is_audited(): void
    {
        [$link, $price] = $this->catalog();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->getJson('/api/v1/public/services')->assertJsonCount(0, 'data');

        $id = $this->postJson('/api/v1/team/service-offerings', $this->offeringPayload($link, $price))->assertCreated()
            ->assertJsonPath('data.online_enabled', false)->json('data.id');
        $this->postJson("/api/v1/team/service-offerings/{$id}/schedule-windows", $this->windowPayload())->assertCreated();

        $this->assertDatabaseHas('service_offerings', ['id' => $id, 'online_enabled' => true, 'online_paused' => false]);
        $this->getJson('/api/v1/public/services')->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $id, 'action' => 'visibility.refreshed']);
    }

    public function test_team_can_pause_and_resume_visibility_with_rbac_and_audit(): void
    {
        $offering = $this->visibleOffering();
        $url = route('api.v1.team.visibility.update', $offering);

        $this->patchJson($url, ['paused' => true])->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]))->patchJson($url, ['paused' => true])->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => UserRole::Supervisor]))
            ->patchJson($url, ['paused' => true])->assertOk()->assertJsonPath('data.online_enabled', false);
        $this->getJson('/api/v1/public/services')->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $offering->id, 'action' => 'visibility.paused']);

        $this->patchJson($url, ['paused' => false])->assertOk()->assertJsonPath('data.online_enabled', true);
        $this->getJson('/api/v1/public/services')->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $offering->id, 'action' => 'visibility.resumed']);
    }

    #[TestWith(['unit', 'operational_enabled', false])]
    #[TestWith(['catalogLink', 'active', false])]
    #[TestWith(['procedurePrice', 'status', 'QUARANTINED'])]
    #[TestWith(['procedurePrice', 'valid_until', '2026-09-01'])]
    #[TestWith(['agreement', 'requires_authorization', true])]
    #[TestWith(['agreement', 'is_default', false])]
    public function test_ineligible_offering_stays_offline(string $relation, string $field, mixed $value): void
    {
        $offering = $this->visibleOffering();
        $offering->{$relation}->update([$field => $value]);
        $this->artisan('schedule:materialize', ['--offering' => $offering->id]);

        $this->assertFalse($offering->fresh()->online_enabled);
        $this->getJson('/api/v1/public/services')->assertJsonCount(0, 'data');
    }

    public function test_offering_without_scale_stays_offline_until_window_is_added(): void
    {
        [$link, $price] = $this->catalog();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $id = $this->postJson('/api/v1/team/service-offerings', $this->offeringPayload($link, $price))->assertCreated()->json('data.id');
        $this->assertFalse(ServiceOffering::findOrFail($id)->online_enabled);
        $this->getJson('/api/v1/public/services')->assertJsonCount(0, 'data');

        $this->postJson("/api/v1/team/service-offerings/{$id}/schedule-windows", $this->windowPayload())->assertCreated();
        $this->assertTrue(ServiceOffering::findOrFail($id)->online_enabled);
        $this->getJson('/api/v1/public/services')->assertJsonCount(1, 'data');
    }

    public function test_origin_price_change_is_mirrored_while_booking_keeps_snapshot(): void
    {
        $offering = $this->visibleOffering();
        $receipt = $this->postJson('/api/v1/public/appointments', [
            'idempotency_key' => (string) Str::uuid(), 'offering_id' => $offering->id, 'starts_at' => '2026-09-02T08:00:00-03:00',
            'patient' => ['name' => 'Paciente Preço', 'cpf' => '12345678909', 'phone' => '85999999999'], 'privacy_consent' => true,
        ])->assertCreated()->assertJsonPath('data.price', '150.00')->json('data');

        $offering->procedurePrice->update(['amount' => '170.00']);
        $this->artisan('schedule:materialize', ['--offering' => $offering->id]);

        $this->assertSame('170.00', $offering->fresh()->price);
        $this->assertTrue($offering->fresh()->online_enabled);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $offering->id, 'action' => 'visibility.refreshed']);
        $this->assertDatabaseHas('appointments', ['id' => $receipt['id'], 'price_snapshot' => '150.00']);
        $this->getJson('/api/v1/public/services')->assertJsonPath('data.0.offerings.0.price', '170.00');
    }

    private function catalog(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-02T07:00:00-03:00'));
        $price = ProcedurePrice::factory()->create(['amount' => '150.00']);
        $price->unit->update(['operational_enabled' => true]);
        $price->agreement->update(['is_default' => true, 'requires_authorization' => false]);
        $professional = Professional::factory()->create();
        $link = CatalogLink::create(['unit_id' => $price->unit_id, 'procedure_id' => $price->procedure_id,
            'agreement_id' => $price->agreement_id, 'professional_id' => $professional->id, 'active' => true]);

        return [$link, $price];
    }

    private function offeringPayload(CatalogLink $link, ProcedurePrice $price): array
    {
        return ['catalog_link_id' => $link->id, 'procedure_price_id' => $price->id, 'booking_mode' => 'FIXED_SLOT',
            'modality' => 'PRESENTIAL', 'duration_override_minutes' => 30, 'daily_capacity' => 10,
            'arrival_block_minutes' => 60, 'arrival_block_capacity' => 3, 'active' => true];
    }

    private function windowPayload(): array
    {
        return ['effective_from' => '2026-09-02', 'effective_to' => null, 'weekday' => 3, 'starts_at' => '08:00',
            'ends_at' => '10:00', 'slot_minutes' => 30, 'daily_capacity_override' => null, 'active' => true];
    }

    private function visibleOffering(): ServiceOffering
    {
        [$link, $price] = $this->catalog();
        $offering = ServiceOffering::factory()->create([
            'unit_id' => $link->unit_id, 'procedure_id' => $link->procedure_id, 'professional_id' => $link->professional_id,
            'agreement_id' => $link->agreement_id, 'catalog_link_id' => $link->id, 'procedure_price_id' => $price->id,
            'price' => '150.00', 'online_enabled' => false, 'online_paused' => false,
        ]);
        $offering->scheduleWindows()->create([...$this->windowPayload(), 'starts_at' => '08:00:00', 'ends_at' => '10:00:00']);
        app(MaterializeScheduleOccurrences::class)->handle($offering);
        app(RefreshOfferingVisibility::class)->handle($offering);

        return $offering->fresh();
    }
}
