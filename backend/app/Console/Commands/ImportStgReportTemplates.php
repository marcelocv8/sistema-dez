<?php

namespace App\Console\Commands;

use App\Services\Stg\ReportTemplateImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportStgReportTemplates extends Command
{
    protected $signature = 'stg:import-report-templates
        {--source= : Diretório com as extrações de laudos do STG}
        {--refresh : Sobrescreve o conteúdo HTML dos modelos já importados}
        {--dry-run : Executa a leitura e o casamento sem persistir}
        {--commit : Confirma a importação no banco}';

    protected $description = 'Importa modelos de laudo e linhas (biblioteca de laudos) legados do STG';

    public function handle(ReportTemplateImporter $importer): int
    {
        if ((bool) $this->option('dry-run') === (bool) $this->option('commit')) {
            $this->error('Informe exatamente uma opção: --dry-run ou --commit.');

            return self::INVALID;
        }

        $source = $this->option('source') ?: config('sistema_dez.stg_laudos_path');

        if (! is_string($source) || $source === '') {
            $this->error('Informe --source ou configure STG_LAUDOS_PATH.');

            return self::INVALID;
        }

        try {
            $summary = $importer->import($source, (bool) $this->option('commit'), (bool) $this->option('refresh'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->info($this->option('commit') ? 'Importação confirmada.' : 'Dry-run concluído; nenhuma alteração persistida.');

        return self::SUCCESS;
    }
}
