<?php

namespace Tests\Feature;

use App\Enums\ImportIssueResolutionStatus;
use App\Enums\PriceStatus;
use App\Models\ProcedurePrice;
use App\Models\StgImportIssue;
use App\Services\Stg\StgCatalogImporter;
use App\Services\Stg\StgIssueResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StgIssueResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_persist_and_commit_is_idempotent(): void
    {
        app(StgCatalogImporter::class)->import($this->fixturePath(), true);

        $resolver = app(StgIssueResolver::class);
        $dryRun = $resolver->resolve(commit: false);

        $this->assertFalse($dryRun['commit']);
        $this->assertSame(0, $dryRun['issues']['changed']);
        $this->assertSame($dryRun['issues']['total'], StgImportIssue::where('resolution_status', ImportIssueResolutionStatus::Open->value)->count());
        $this->assertSame(2, ProcedurePrice::where('status', PriceStatus::Quarantined)->count());

        $first = $resolver->resolve(commit: true);
        $second = $resolver->resolve(commit: true);

        $this->assertSame($first['issues']['matched'], $first['issues']['changed']);
        $this->assertSame(0, $second['issues']['changed']);
        $this->assertSame(0, $second['issues']['conflicts']);
        $this->assertDatabaseHas('stg_import_issues', [
            'code' => 'AMBIGUOUS_PRICE',
            'resolution_status' => ImportIssueResolutionStatus::Resolved->value,
        ]);
        $this->assertSame(1, ProcedurePrice::where('amount', '120.00')->where('status', PriceStatus::Valid)->count());
        $this->assertSame(1, ProcedurePrice::where('amount', '100.00')->where('status', PriceStatus::Quarantined)->count());
    }

    private function fixturePath(): string
    {
        return base_path('tests/Fixtures/stg');
    }
}
