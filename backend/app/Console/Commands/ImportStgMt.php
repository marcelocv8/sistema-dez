<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Stg\StgMtImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Throwable;

class ImportStgMt extends Command
{
    protected $signature = 'stg:import-mt
        {--source= : Diretório com os CSVs MT (subpasta mt/ da extração STG)}
        {--dry-run : Executa toda a reconciliação e desfaz a transação}
        {--commit : Confirma a importação no banco}';

    protected $description = 'Importa Medicina do Trabalho do STG (riscos, setores, funções e ASOs)';

    public function handle(StgMtImporter $importer): int
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

        $createdBy = (int) (Auth::id() ?? 0);

        if ($createdBy === 0) {
            $admin = User::query()->where('role', 'ADMIN')->orderBy('id')->first();

            if ($admin === null) {
                $this->error('Nenhum usuário Admin para registrar como autor do import.');

                return self::INVALID;
            }

            $createdBy = $admin->id;
        }

        try {
            $summary = $importer->import($source, (bool) $this->option('commit'), $createdBy);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->info($this->option('commit') ? 'Importação confirmada.' : 'Dry-run concluído; nenhuma alteração persistida.');

        return self::SUCCESS;
    }
}
