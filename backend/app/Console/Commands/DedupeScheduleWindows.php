<?php

namespace App\Console\Commands;

use App\Models\ScheduleWindow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class DedupeScheduleWindows extends Command
{
    protected $signature = 'schedule:dedupe-windows
        {--dry-run : Simula e desfaz a transação}
        {--commit : Confirma a limpeza}';

    protected $description = 'Remove janelas de escala duplicadas (mesma oferta × dia da semana × horário), que fazem o schedule:materialize falhar com "ON CONFLICT DO UPDATE command cannot affect row a second time"';

    public function handle(): int
    {
        if ((bool) $this->option('dry-run') === (bool) $this->option('commit')) {
            $this->error('Informe exatamente uma opção: --dry-run ou --commit.');

            return self::INVALID;
        }

        $commit = (bool) $this->option('commit');

        DB::beginTransaction();

        try {
            $summary = $this->dedupe();
            $commit ? DB::commit() : DB::rollBack();
        } catch (Throwable $exception) {
            DB::rollBack();
            $this->error('Falha na limpeza: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode([...$summary, 'commit' => $commit], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        if (! $commit) {
            $this->info('Dry-run concluído; nenhuma alteração persistida.');

            return self::SUCCESS;
        }

        $this->info('Janelas duplicadas removidas. Rode `php artisan schedule:materialize`.');

        return self::SUCCESS;
    }

    /** @return array<string, int> */
    private function dedupe(): array
    {
        $groups = ScheduleWindow::query()
            ->where('active', true)
            ->get(['id', 'service_offering_id', 'weekday', 'starts_at', 'created_at'])
            ->groupBy(fn (ScheduleWindow $window): string => $window->service_offering_id.'|'.$window->weekday.'|'.(string) $window->starts_at)
            ->filter(fn ($group): bool => $group->count() > 1);

        $windowsRemoved = 0;
        $offersAffected = 0;

        foreach ($groups as $group) {
            $keepers = $group->sortBy('created_at');
            $keep = $keepers->first();
            $offersAffected++;

            foreach ($keepers as $window) {
                if ($window->id === $keep->id) {
                    continue;
                }

                // as ocorrências apontam para a janela com nullOnDelete: nada quebra
                $window->delete();
                $windowsRemoved++;
            }
        }

        return [
            'grupos_duplicados' => $groups->count(),
            'ofertas_afetadas' => $offersAffected,
            'janelas_removidas' => $windowsRemoved,
        ];
    }
}
