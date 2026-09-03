<?php

namespace Tests\Feature;

use App\Enums\PriceStatus;
use App\Enums\ProcedureType;
use App\Enums\UserRole;
use App\Models\Agreement;
use App\Models\Procedure;
use App\Models\ProcedurePrice;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamPriceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_reception_searches_active_current_prices_with_particular_as_default(): void
    {
        $user = User::factory()->create(['role' => UserRole::Reception]);
        $particular = Agreement::factory()->create(['legacy_id' => '49', 'name' => 'PARTICULAR M', 'active' => true, 'is_default' => true]);
        $unit = Unit::factory()->create(['legacy_id' => '1']);
        $procedure = Procedure::factory()->create([
            'legacy_id' => '189',
            'name' => 'Ultrassonografia da Tireóide',
            'search_name' => 'ULTRASSONOGRAFIA DA TIREOIDE',
            'tuss_code' => '40901222',
            'type' => ProcedureType::Exam,
            'group_name' => 'ULTRASSONOGRAFIA',
            'active' => true,
        ]);
        ProcedurePrice::factory()->create([
            'procedure_id' => $procedure,
            'agreement_id' => $particular,
            'unit_id' => $unit,
            'amount' => '145.00',
        ]);
        ProcedurePrice::factory()->create([
            'procedure_id' => $procedure,
            'agreement_id' => $particular,
            'unit_id' => $unit,
            'amount' => '999.00',
            'status' => PriceStatus::Quarantined,
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/team/prices?q=tireoide&type=EXAM')
            ->assertOk()
            ->assertJsonPath('data.agreement_id', $particular->id)
            ->assertJsonPath('data.types.0.type', 'EXAM')
            ->assertJsonPath('data.types.0.groups.0.group_name', 'ULTRASSONOGRAFIA')
            ->assertJsonPath('data.types.0.groups.0.items.0.amount', '145.00')
            ->assertJsonCount(1, 'data.types.0.groups.0.items');

        $this->actingAs($user)->getJson('/api/v1/team/prices?q=40901222')->assertOk()
            ->assertJsonPath('data.types.0.groups.0.items.0.procedure.legacy_id', '189');
    }

    public function test_prices_list_without_search_and_permission(): void
    {
        $user = User::factory()->create(['role' => UserRole::Reception]);
        $particular = Agreement::factory()->create(['legacy_id' => '49', 'name' => 'PARTICULAR M', 'active' => true, 'is_default' => true]);
        $procedure = Procedure::factory()->create(['legacy_id' => '189', 'name' => 'US TIREOIDE', 'search_name' => 'US TIREOIDE', 'type' => ProcedureType::Exam, 'group_name' => 'ULTRASSONOGRAFIA', 'active' => true]);
        ProcedurePrice::factory()->create(['procedure_id' => $procedure, 'agreement_id' => $particular, 'unit_id' => null, 'amount' => '145.00']);

        $this->actingAs($user)->getJson('/api/v1/team/prices?q=ab')->assertOk();
        $this->actingAs($user)->getJson('/api/v1/team/prices')->assertOk()
            ->assertJsonPath('data.query', '')
            ->assertJsonCount(1, 'data.types.0.groups.0.items');

        $user->update(['active' => false]);
        $this->actingAs($user->fresh())->getJson('/api/v1/team/price-options')->assertForbidden();
    }
}
