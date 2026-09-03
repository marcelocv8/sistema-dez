<?php

namespace App\Console\Commands;

use App\Services\Stg\StgCatalogImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportStgCatalog extends Command
{
    protected $signature = 'stg:import-catalog
        {--source= : Diretório com as nove fontes STG}
        {--dry-run : Executa toda a reconciliação e desfaz a transação}
        {--commit : Confirma a importação no banco}';

    protected $description = 'Importa catálogo, classificações e preços legados do STG';

    public function handle(StgCatalogImporter $importer): int
    {
        if ((bool) $this->option('dry-run') === (bool) $this->option('commit')) {
            $this->error('Informe exatamente uma opção: --dry-run ou --commit.');

            return self::INVALID;
        }

        $source = $this->option('source') ?: config('sistema_dez.stg_import_path');

        if (! is_string($source) || $source === '') {
            $this->error('Informe --source ou configure STG_IMPORT_PATH.');

            return self::INVALID;
        }

        try {
            $summary = $importer->import($source, (bool) $this->option('commit'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->info($this->option('commit') ? 'Importação confirmada.' : 'Dry-run concluído; nenhuma alteração persistida.');

        return self::SUCCESS;
    }
}
