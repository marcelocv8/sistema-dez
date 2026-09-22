<?php

namespace App\Console\Commands;

use App\Models\Professional;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ImportStgDoctors extends Command
{
    protected $signature = 'stg:import-doctors
        {--only-agenda : Importa apenas quem tem medico_agenda = true no legado}
        {--include-inactive : Inclui operadores inativos}
        {--max=200 : Limite de segurança para a seleção}
        {--allow-large : Permite seleção acima do --max}
        {--dry-run : Simula e desfaz a transação}
        {--commit : Confirma}';

    protected $description = 'Importa os médicos reais do STG (tb_operador do schema do legado) para professionals, reaproveitando registros existentes por nome';

    public function handle(): int
    {
        if ((bool) $this->option('dry-run') === (bool) $this->option('commit')) {
            $this->error('Informe exatamente uma opção: --dry-run ou --commit.');

            return self::INVALID;
        }

        if (config('database.connections.stg.username') === null) {
            $this->error('Configure STG_DB_USERNAME/STG_DB_PASSWORD/STG_DB_DATABASE (e STG_DB_SCHEMA) no .env.');

            return self::INVALID;
        }

        try {
            DB::connection('stg')->statement('SET default_transaction_read_only = on');

            $rows = $this->readDoctors();
        } catch (Throwable $exception) {
            $this->error('Falha ao ler o legado: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($rows->isEmpty()) {
            $this->warn('Nenhum médico encontrado com os filtros atuais no legado.');

            return self::SUCCESS;
        }

        // Guarda de segurança: a flag `medico` do legado está true em TODOS os
        // operadores ativos (1418 em 22/09), então um filtro errado importaria
        // a base inteira e poluiria a tela de profissionais.
        $limit = (int) ($this->option('max') ?: 200);

        if ($rows->count() > $limit && ! (bool) $this->option('allow-large')) {
            $this->error(sprintf(
                'A seleção tem %d registros (limite %d). Isso normalmente indica filtro errado. Ajuste os filtros, use --only-agenda, ou confirme com --max=%d --allow-large.',
                $rows->count(),
                $limit,
                $rows->count(),
            ));

            return self::INVALID;
        }

        $commit = (bool) $this->option('commit');
        $stats = [
            'legado_lidos' => $rows->count(),
            'legado_medico' => $rows->where('medico', true)->count(),
            'legado_medico_agenda' => $rows->where('medico_agenda', true)->count(),
            'legado_profissional' => $rows->where('profissional', true)->count(),
            'legado_inativos' => $rows->where('ativo', false)->count(),
        ];

        $summary = DB::transaction(function () use ($rows): array {
            $created = 0;
            $updated = 0;
            $matchedNames = [];
            $touched = [];

            foreach ($rows as $row) {
                $legacyId = (string) $row->operador_id;
                $name = Str::of((string) $row->nome)->squish()->toString();

                if ($name === '') {
                    continue;
                }

                $normalized = Str::of($name)->ascii()->squish()->upper()->toString();
                $active = (bool) $row->ativo;

                $professional = Professional::query()->where('search_name', $normalized)->first()
                    ?? Professional::query()->where('legacy_id', $legacyId)->first();

                $attributes = [
                    'name' => $name,
                    'search_name' => $normalized,
                    'council_number' => $this->nullable($row->conselho),
                    'active' => $active,
                    'classification_status' => $active ? 'ATIVO' : 'SEM_MOVIMENTO',
                ];

                if ($professional instanceof Professional) {
                    $professional->fill($attributes);
                    $professional->save();
                    $updated++;
                    $touched[$professional->id] = true;
                    $matchedNames[$normalized] = $professional->name;

                    continue;
                }

                $createdProfessional = Professional::query()->create([
                    ...$attributes,
                    'legacy_id' => $legacyId,
                    'online_enabled' => false,
                ]);
                $created++;
                $touched[$createdProfessional->id] = true;
            }

            $extra = Professional::query()
                ->whereNotNull('legacy_id')
                ->whereNotIn('id', array_keys($touched))
                ->orderBy('name')
                ->get(['name', 'active']);

            return [
                'criados' => $created,
                'atualizados' => $updated,
                'total_medicos_no_dez' => count($touched),
                'nao_mencionados_no_legado' => [
                    'total' => $extra->count(),
                    'ativos' => $extra->where('active', true)->count(),
                    'exemplos' => $extra->take(10)->pluck('name')->all(),
                ],
            ];
        });

        $payload = [...$stats, ...$summary, 'commit' => $commit];

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        if (! $commit) {
            $this->info('Dry-run concluído; nenhuma alteração persistida.');

            return self::SUCCESS;
        }

        $this->info('Médicos importados. Rode `php artisan stg:import-grade --commit --procedure=499` para refazer a agenda e `php artisan schedule:materialize`.');

        return self::SUCCESS;
    }

    /** @return Collection<int, object> */
    private function readDoctors(): Collection
    {
        $query = DB::connection('stg')->table('tb_operador')
            ->select(['operador_id', 'nome', 'conselho', 'ativo', 'medico', 'medico_agenda', 'profissional'])
            ->orderBy('nome');

        if ((bool) $this->option('only-agenda')) {
            $query->where('medico_agenda', true);
        } else {
            $query->where(fn ($builder) => $builder->where('medico', true)->orWhere('medico_agenda', true)->orWhere('profissional', true));
        }

        if (! (bool) $this->option('include-inactive')) {
            $query->where('ativo', true);
        }

        return $query->get();
    }

    private function nullable(mixed $value): ?string
    {
        $clean = trim((string) $value);

        return $clean === '' ? null : $clean;
    }
}
