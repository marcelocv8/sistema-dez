<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ServiceOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ControlledPublicationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_publication_requires_explicit_confirmation_and_is_audited(): void
    {
        $offering = $this->offering();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $url = route('api.v1.team.publication.update', $offering);
        $this->getJson('/api/v1/public/services')->assertJsonCount(0, 'data');
        $this->putJson($url, ['published_until' => '2026-09-15'])->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->putJson($url, ['published_until' => '2026-09-15', 'confirmed' => true])->assertOk()->assertJsonPath('data.online_enabled', true);
        $this->getJson('/api/v1/public/services')->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $offering->id, 'action' => 'offering.published']);
        $this->deleteJson($url)->assertOk()->assertJsonPath('data.online_enabled', false);
        $this->getJson('/api/v1/public/services')->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('audit_logs', ['action' => 'offering.unpublished']);
    }

    public function test_reception_cannot_publish_and_unauthenticated_request_returns_401(): void
    {
        $offering = $this->offering();
        $url = route('api.v1.team.publication.update', $offering);
        $data = ['published_until' => '2026-09-15', 'confirmed' => true];
        $this->putJson($url, $data)->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => UserRole::Reception]))->putJson($url, $data)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::Supervisor]))->putJson($url, $data)->assertOk();
    }

    #[TestWith(['unit', 'operational_enabled', false])]
    #[TestWith(['professional', 'online_enabled', false])]
    #[TestWith(['procedure', 'online_enabled', false])]
    #[TestWith(['agreement', 'requires_authorization', true])]
    #[TestWith(['catalogLink', 'active', false])]
    #[TestWith(['procedurePrice', 'status', 'QUARANTINED'])]
    #[TestWith(['procedurePrice', 'valid_until', '2026-09-01'])]
    public function test_ineligible_catalog_prevents_publication(string $relation, string $field, mixed $value): void
    {
        $offering = $this->offering();
        $offering->{$relation}->update([$field => $value]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->putJson(route('api.v1.team.publication.update', $offering), ['published_until' => '2026-09-15', 'confirmed' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('publication');
        $this->assertFalse($offering->fresh()->online_enabled);
    }

    public function test_missing_scale_and_excessive_publication_horizon_are_rejected(): void
    {
        $offering = $this->offering();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $url = route('api.v1.team.publication.update', $offering);
        $this->putJson($url, ['published_until' => '2026-11-01', 'confirmed' => true])->assertUnprocessable();
        $offering->scheduleWindows()->update(['active' => false]);
        $this->putJson($url, ['published_until' => '2026-09-15', 'confirmed' => true])->assertUnprocessable()->assertJsonValidationErrors('publication');
    }

    public function test_expired_publication_and_later_guide_requirement_hide_service(): void
    {
        $offering = $this->offering();
        $offering->forceFill(['online_enabled' => true, 'published_until' => '2026-09-01'])->save();
        $this->getJson('/api/v1/public/services')->assertJsonCount(0, 'data');
        $offering->forceFill(['published_until' => '2026-09-15'])->save();
        $offering->agreement->update(['requires_authorization' => true]);
        $this->getJson('/api/v1/public/services')->assertJsonCount(0, 'data');
    }

    private function offering(): ServiceOffering
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-02T07:00:00-03:00'));
        $offering = ServiceOffering::factory()->published()->create(['price' => '150.00']);
        $offering->forceFill(['online_enabled' => false, 'published_until' => null])->save();
        $offering->scheduleWindows()->create(['effective_from' => '2026-09-02', 'weekday' => 3, 'starts_at' => '08:00', 'ends_at' => '10:00', 'slot_minutes' => 30, 'active' => true]);

        return $offering;
    }
}
