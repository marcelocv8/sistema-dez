<?php

namespace Tests\Feature;

use App\Enums\AsoStatus;
use App\Enums\AsoType;
use App\Models\Agreement;
use App\Models\MtAso;
use App\Models\Patient;
use App\Models\StgImportIssue;
use App\Models\StgImportRun;
use App\Models\User;
use App\Services\Stg\StgMtImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StgMtImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_rolls_back_every_change(): void
    {
        $this->prepareContext();

        $summary = app(StgMtImporter::class)->import($this->fixturePath(), false, $this->author->id);

        $this->assertSame(2, $summary['risks']['created']);
        $this->assertDatabaseCount('mt_risks', 0);
        $this->assertDatabaseCount('mt_asos', 0);
        $this->assertDatabaseCount('stg_import_runs', 0);
    }

    public function test_commit_imports_catalog_and_aos_idempotently(): void
    {
        $this->prepareContext();
        $importer = app(StgMtImporter::class);

        $first = $importer->import($this->fixturePath(), true, $this->author->id);
        $second = $importer->import($this->fixturePath(), true, $this->author->id);

        $this->assertSame(2, $first['risks']['created']);
        $this->assertSame(2, $first['sectors']['created']);
        $this->assertSame(2, $first['functions']['created']);
        $this->assertSame(4, $first['asos_created']);
        $this->assertSame(1, $first['asos_skipped_inactive']);

        $this->assertSame(0, $second['risks']['created']);
        $this->assertSame(2, $second['risks']['updated']);
        $this->assertSame(4, $second['asos_updated']);
        $this->assertSame(0, $second['asos_created']);

        $this->assertDatabaseCount('mt_risks', 2);
        $this->assertDatabaseCount('mt_sectors', 2);
        $this->assertDatabaseCount('mt_functions', 2);
        $this->assertDatabaseCount('mt_asos', 4);

        $agreement = Agreement::where('legacy_id', 49)->firstOrFail();
        $aso = MtAso::where('legacy_id', 100)->firstOrFail();
        $this->assertSame(AsoType::Periodico, $aso->type);
        $this->assertSame(AsoStatus::Emitido, $aso->status);
        $this->assertSame($agreement->id, $aso->agreement_id);
        $this->assertSame('2026-09-10', $aso->expires_at->toDateString());
        $this->assertSame(2, $aso->risks()->count());
        $this->assertSame('PRODUCAO', $aso->sector?->name);
        $this->assertSame('OPERADOR DE MAQUINAS', $aso->function?->name);

        $pending = MtAso::where('legacy_id', 101)->firstOrFail();
        $this->assertSame(AsoStatus::Pendente, $pending->status);
        $this->assertSame(AsoType::Admissional, $pending->type);
        $this->assertNull($pending->expires_at);

        $weirdType = MtAso::where('legacy_id', 102)->firstOrFail();
        $this->assertSame(AsoType::Periodico, $weirdType->type);

        $orphan = MtAso::where('legacy_id', 105)->firstOrFail();
        $this->assertNull($orphan->sector_id);
        $this->assertNull($orphan->function_id);

        $latestRunId = StgImportRun::latest('started_at')->value('id');
        $this->assertSame(4, StgImportIssue::where('import_run_id', $latestRunId)->count());
        $this->assertDatabaseHas('stg_import_issues', ['import_run_id' => $latestRunId, 'code' => 'MT_AGREEMENT_NOT_FOUND', 'entity_type' => 'mt_sector']);
        $this->assertDatabaseHas('stg_import_issues', ['import_run_id' => $latestRunId, 'code' => 'MT_SECTOR_NOT_FOUND', 'entity_type' => 'mt_function']);
        $this->assertDatabaseHas('stg_import_issues', ['import_run_id' => $latestRunId, 'code' => 'MT_ASO_TYPE_UNKNOWN', 'entity_type' => 'mt_aso']);
        $this->assertDatabaseHas('stg_import_issues', ['import_run_id' => $latestRunId, 'code' => 'MT_AGREEMENT_NOT_FOUND', 'entity_type' => 'mt_aso']);
    }

    private function prepareContext(): void
    {
        $this->author = User::factory()->create(['role' => 'ADMIN']);

        Agreement::factory()->create(['legacy_id' => 49, 'name' => 'PARTICULAR M']);

        foreach (range(1001, 1006) as $legacyPatientId) {
            Patient::factory()->create(['legacy_id' => $legacyPatientId]);
        }
    }

    private function fixturePath(): string
    {
        return base_path('tests/Fixtures/stg-mt');
    }
}
