<?php

namespace Tests\Feature;

use App\Enums\PriceStatus;
use App\Models\Procedure;
use App\Models\ProcedurePrice;
use App\Models\StgImportIssue;
use App\Models\StgImportRun;
use App\Services\Stg\StgCatalogImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StgCatalogImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_rolls_back_every_change(): void
    {
        $summary = app(StgCatalogImporter::class)->import($this->fixturePath(), false);

        $this->assertSame(2, $summary['source_records']['procedures']);
        $this->assertDatabaseCount('procedures', 0);
        $this->assertDatabaseCount('stg_import_runs', 0);
    }

    public function test_commit_is_repeatable_and_quarantines_conflicting_prices(): void
    {
        $importer = app(StgCatalogImporter::class);
        $first = $importer->import($this->fixturePath(), true);
        $second = $importer->import($this->fixturePath(), true);

        $this->assertSame($first['catalog'], $second['catalog']);
        $this->assertSame($first['prices'], $second['prices']);
        $this->assertDatabaseCount('procedures', 2);
        $this->assertDatabaseCount('stg_import_runs', 2);
        $this->assertSame(2, ProcedurePrice::where('status', PriceStatus::Quarantined)->count());
        $this->assertSame(1, StgImportIssue::where('code', 'AMBIGUOUS_PRICE')->where('legacy_id', '145')->where('import_run_id', StgImportRun::latest('started_at')->value('id'))->count());
        $latestRunId = StgImportRun::latest('started_at')->value('id');
        $this->assertSame(3, StgImportIssue::where('code', 'LEGACY_ID_NOT_FOUND')->where('import_run_id', $latestRunId)->count());
        $this->assertDatabaseHas('stg_import_issues', ['import_run_id' => $latestRunId, 'code' => 'LEGACY_ID_NOT_FOUND', 'entity_type' => 'procedure']);
        $this->assertDatabaseHas('stg_import_issues', ['import_run_id' => $latestRunId, 'code' => 'LEGACY_ID_NOT_FOUND', 'entity_type' => 'professional']);
        $this->assertFalse(Procedure::where('legacy_id', '145')->value('online_enabled'));
    }

    private function fixturePath(): string
    {
        return base_path('tests/Fixtures/stg');
    }
}
