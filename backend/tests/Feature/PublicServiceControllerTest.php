<?php

namespace Tests\Feature;

use App\Enums\Modality;
use App\Models\Agreement;
use App\Models\Procedure;
use App\Models\ServiceOffering;
use App\Models\Unit;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PublicServiceControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_lists_only_services_available_online_with_the_public_contract(): void
    {
        $unit = Unit::factory()->create(['name' => 'Clínica DEZ Aldeota']);
        $agreement = Agreement::factory()->create(['name' => 'Particular']);
        $procedure = Procedure::factory()->create(['name' => 'Consulta Endocrinológica']);
        $offering = ServiceOffering::factory()->published()->for($unit)->for($agreement)->for($procedure)->create([
            'modality' => Modality::Presential,
            'price' => 250,
        ]);
        ServiceOffering::factory()->create(['active' => false]);

        $response = $this->getJson(route('api.v1.public.services.index', [
            'unit_id' => $unit->id,
            'agreement_id' => $agreement->id,
            'modality' => Modality::Presential->value,
        ]));

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $procedure->id)
            ->assertJsonPath('data.0.name', 'Consulta Endocrinológica')
            ->assertJsonPath('data.0.offerings.0.id', $offering->id)
            ->assertJsonPath('data.0.offerings.0.unit.name', 'Clínica DEZ Aldeota')
            ->assertJsonPath('data.0.offerings.0.price', '250.00');
    }

    public function test_it_validates_public_service_filters(): void
    {
        $this->getJson(route('api.v1.public.services.index', ['unit_id' => 'invalid']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('unit_id');
    }
}
