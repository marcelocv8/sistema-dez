<?php

namespace App\Console\Commands;

use App\Models\Report;
use App\Models\ReportEvent;
use Illuminate\Console\Command;

class PurgeExpiredReports extends Command
{
    protected $signature = 'reports:purge-expired
        {--dry-run : Lista os laudos que seriam expurgados}
        {--commit : Confirma o expurgo (zera o conteúdo, mantém a trilha)}';

    protected $description = 'Expurga conteúdo de laudos vencidos (retenção de 5 anos, decisão do dono §6.4)';

    public function handle(): int
    {
        if ((bool) $this->option('dry-run') === (bool) $this->option('commit')) {
            $this->error('Informe exatamente uma opção: --dry-run ou --commit.');

            return self::INVALID;
        }

        $expired = Report::query()
            ->where('status', Report::STATUS_FINALIZADO)
            ->whereNotNull('retention_until')
            ->where('retention_until', '<', now()->toDateString())
            ->whereNotNull('content_html')
            ->get(['id', 'retention_until']);

        foreach ($expired as $report) {
            $this->line("report={$report->id} retention_until={$report->retention_until?->toDateString()}");
        }

        if ($expired->isEmpty()) {
            $this->info('Nenhum laudo vencido.');

            return self::SUCCESS;
        }

        if ((bool) $this->option('dry-run')) {
            $this->info("{$expired->count()} laudo(s) seriam expurgados (dry-run; nada alterado).");

            return self::SUCCESS;
        }

        $count = 0;
        foreach ($expired as $report) {
            $report->forceFill(['content_html' => null])->save();
            $report->events()->create(['event' => ReportEvent::PURGED, 'by_user_id' => $report->typed_by]);
            $count++;
        }
        $this->info("{$count} laudo(s) expurgados; trilha de auditoria preservada.");

        return self::SUCCESS;
    }
}
