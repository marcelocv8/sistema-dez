<?php

namespace App\Console\Commands;

use App\Services\Stg\StgIssueResolver;
use Illuminate\Console\Command;
use Throwable;

class ResolveStgIssues extends Command
{
    protected $signature = 'stg:resolve-issues
        {--run= : UUID da execução STG; por padrão usa a mais recente}
        {--dry-run : Mostra o plano sem persistir alterações}
        {--commit : Aplica as decisões em uma transação}';

    protected $description = 'Materializa no banco as decisões documentadas para as pendências da importação STG';

    public function handle(StgIssueResolver $resolver): int
    {
        if ((bool) $this->option('dry-run') === (bool) $this->option('commit')) {
            $this->error('Informe exatamente uma opção: --dry-run ou --commit.');

            return self::INVALID;
        }

        try {
            $summary = $resolver->resolve(
                is_string($this->option('run')) ? $this->option('run') : null,
                (bool) $this->option('commit'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->info($this->option('commit') ? 'Resoluções confirmadas.' : 'Dry-run concluído; nenhuma alteração persistida.');

        return self::SUCCESS;
    }
}
