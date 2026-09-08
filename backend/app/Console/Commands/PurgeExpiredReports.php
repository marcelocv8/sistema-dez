<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Report;
use App\Models\ReportEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeExpiredReports extends Command
{
    protected $signature = 'reports:purge-expired {--dry-run} {--commit}';

    protected $description = 'Expurga conteúdo vencido, preservando identificação e trilha das ações';

    public function handle(): int
    {
        if ((bool) $this->option('dry-run') === (bool) $this->option('commit')) {
            $this->error('Informe exatamente uma opção: --dry-run ou --commit.');

            return self::INVALID;
        }
        $ids = Report::query()->where('status', Report::STATUS_FINALIZADO)->whereNull('purged_at')
            ->where('retention_until', '<', now(config('sistema_dez.timezone'))->toDateString())->pluck('id');
        foreach ($ids as $id) {
            $this->line('report='.$id);
            if ($this->option('dry-run')) {
                continue;
            }
            DB::transaction(function () use ($id): void {
                $report = Report::query()->lockForUpdate()->findOrFail($id);
                if ($report->purged_at !== null) {
                    return;
                }
                $report->forceFill(['content_html' => '', 'content_backup' => null, 'adendo' => null, 'purged_at' => now(), 'portal_unpublished_at' => now()])->save();
                // Retention removes clinical payload only; actors, actions and dates remain intact.
                foreach (AuditLog::query()->where('entity_type', $report->getMorphClass())->where('entity_key', $id)->lockForUpdate()->get() as $audit) {
                    $changes = [];
                    foreach (['before', 'after'] as $field) {
                        $snapshot = $audit->$field;
                        if (! is_array($snapshot)) {
                            continue;
                        }
                        foreach (['content_html', 'content_backup', 'adendo'] as $clinicalField) {
                            if (array_key_exists($clinicalField, $snapshot)) {
                                if (is_string($snapshot[$clinicalField])) {
                                    $snapshot[$clinicalField.'_sha256'] = hash('sha256', $snapshot[$clinicalField]);
                                }
                                unset($snapshot[$clinicalField]);
                            }
                        }
                        $changes[$field] = $snapshot;
                    }
                    $audit->forceFill([...$changes, 'metadata' => [...($audit->metadata ?? []), 'clinical_payload_purged_at' => now()->toIso8601String()]])->save();
                }
                $report->events()->create(['event' => ReportEvent::PURGED, 'by_user_id' => null, 'payload' => ['origin' => 'reports:purge-expired']]);
            }, 3);
        }
        $this->info($ids->count().' laudo(s) '.($this->option('dry-run') ? 'seriam expurgados.' : 'expurgados; trilha preservada.'));

        return self::SUCCESS;
    }
}
