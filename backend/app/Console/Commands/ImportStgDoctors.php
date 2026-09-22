<?php

namespace App\Console\Commands;

use App\Models\Professional;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ImportStgDoctors extends Command
{
    /** @var array<string, int|string> */
    private array $sourceCounts = [];

    protected $signature = 'stg:import-doctors
        {--source=agenda : População de médicos: agenda | percentual | both | flags}
        {--only-agenda : (source=flags) Importa apenas quem tem medico_agenda = true}
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

        $payload = [...$stats, ...$this->sourceCounts, ...$summary, 'commit' => $commit];

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
        $source = (string) ($this->option('source') ?: 'agenda');

        if (! in_array($source, ['agenda', 'percentual', 'both', 'flags'], true)) {
            throw new RuntimeException("--source inválido: {$source} (use agenda, percentual, both ou flags)");
        }

        $agendaIds = [];
        $percentualIds = [];

        if (in_array($source, ['agenda', 'both'], true)) {
            $agendaIds = $this->idsFromAgenda();
        }

        if (in_array($source, ['percentual', 'both'], true)) {
            $percentualIds = $this->idsFromPercentual();
        }

        $this->sourceCounts = ['fonte' => $source, 'ids_agenda' => count($agendaIds), 'ids_percentual' => count($percentualIds)];

        $query = DB::connection('stg')->table('tb_operador')
            ->select(['operador_id', 'nome', 'conselho', 'ativo', 'medico', 'medico_agenda', 'profissional'])
            ->orderBy('nome');

        if ($source === 'flags') {
            if ((bool) $this->option('only-agenda')) {
                $query->where('medico_agenda', true);
            } else {
                $query->where(fn ($builder) => $builder->where('medico', true)->orWhere('medico_agenda', true)->orWhere('profissional', true));
            }
        } else {
            $query->whereIn('operador_id', array_values(array_unique([...$agendaIds, ...$percentualIds])));
        }

        if (! (bool) $this->option('include-inactive')) {
            $query->where('ativo', true);
        }

        return $query->get();
    }

    /** Ids dos médicos referenciados pela grade (tb_agenda.medico_id e o campo multi). */
    private function idsFromAgenda(): array
    {
        $ids = [];

        foreach (DB::connection('stg')->table('tb_agenda')->where('ativo', true)->get(['medico_id', 'medico_id_multiplo']) as $row) {
            $id = (int) $row->medico_id;

            if ($id > 0) {
                $ids[$id] = true;
            }

            foreach (preg_split('/\D+/', (string) $row->medico_id_multiplo) ?: [] as $extra) {
                $extra = (int) $extra;

                if ($extra > 0) {
                    $ids[$extra] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /** Ids dos médicos com comissão cadastrada por procedimento. */
    private function idsFromPercentual(): array
    {
        return DB::connection('stg')->table('tb_procedimento_percentual_medico')
            ->where('ativo', true)
            ->whereNotNull('medico')
            ->distinct()
            ->pluck('medico')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function nullable(mixed $value): ?string
    {
        $clean = trim((string) $value);

        return $clean === '' ? null : $clean;
    }
}
