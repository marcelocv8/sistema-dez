<?php

namespace Tests\Feature;

use App\Actions\Scheduling\MaterializeScheduleOccurrences;
use App\Models\CatalogLink;
use App\Models\ProcedurePrice;
use App\Models\Professional;
use App\Models\ScheduleOccurrence;
use App\Models\ServiceOffering;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class MaterializeOverlappingWindowsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_janelas_sobrepostas_nao_duplicam_o_mesmo_slot(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T07:00:00-03:00'));
        $offering = $this->offering();

        // A fusão de apelidos move a janela do apelido para a oferta do médico
        // real, que já podia ter uma janela sobreposta a ela.
        $offering->scheduleWindows()->create(['effective_from' => '2026-09-01', 'weekday' => 5,
            'starts_at' => '08:30:00', 'ends_at' => '10:00:00', 'slot_minutes' => 30, 'active' => true]);

        $day = CarbonImmutable::parse('2026-09-04T00:00:00-03:00');
        $count = app(MaterializeScheduleOccurrences::class)->handle($offering, $day, $day);

        // 08:00 e 08:30 (janelas), 09:00 e 09:30 — o 08:30 não conta duas vezes.
        $this->assertSame(4, $count);
        $this->assertSame(4, ScheduleOccurrence::query()
            ->where('service_offering_id', $offering->id)
            ->where('date', '2026-09-04')
            ->count());
    }

    private function offering(): ServiceOffering
    {
        $price = ProcedurePrice::factory()->create(['amount' => '150.00']);
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

        return $offering;
    }
}
