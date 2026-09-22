<?php

namespace App\Console\Commands;

use App\Models\Professional;
use App\Services\Stg\LegacyNameNormalizer;
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
        {--source=grade : População de médicos: grade | appointments | agenda | percentual | both | flags}
        {--grade-file= : CSV da grade (padrão: STG_IMPORT_PATH/grade_medicos.csv)}
        {--months=24 : (source=appointments) janela de histórico em meses}
        {--create-missing : Cria o profissional a partir do nome quando não há operador correspondente}
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

        // ATENÇÃO: `DB::transaction($closure)` faz COMMIT quando o closure
        // retorna — um "dry-run" com ele persistiria de verdade. Por isso o
        // commit/rollback é explícito aqui.
        DB::beginTransaction();

        try {
            $summary = (function () use ($rows): array {
                $created = 0;
                $updated = 0;
                $matchedNames = [];
                $touched = [];

                foreach ($rows as $row) {
                    $legacyId = trim((string) ($row->operador_id ?? ''));
                    $name = Str::of((string) $row->nome)->squish()->toString();

                    if ($name === '') {
                        continue;
                    }

                    $normalized = $this->normalize($name);
                    $active = (bool) $row->ativo;

                    $professional = Professional::query()->where('search_name', $normalized)->first();

                    if ($professional === null && $legacyId !== '') {
                        $professional = Professional::query()->where('legacy_id', $legacyId)->first();
                    }

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
                        'legacy_id' => $legacyId === '' ? null : $legacyId,
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
            })();

            $commit ? DB::commit() : DB::rollBack();
        } catch (Throwable $exception) {
            DB::rollBack();
            $this->error('Falha ao importar médicos: '.$exception->getMessage());

            return self::FAILURE;
        }

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
        $source = (string) ($this->option('source') ?: 'grade');

        if (! in_array($source, ['grade', 'appointments', 'agenda', 'percentual', 'both', 'flags'], true)) {
            throw new RuntimeException("--source inválido: {$source} (use grade, appointments, agenda, percentual, both ou flags)");
        }

        $agendaIds = [];
        $percentualIds = [];
        $appointmentIds = [];
        $gradeNames = [];

        if ($source === 'grade') {
            $gradeNames = $this->namesFromGrade();
        }

        if (in_array($source, ['agenda', 'both'], true)) {
            $agendaIds = $this->idsFromAgenda();
        }

        if (in_array($source, ['percentual', 'both'], true)) {
            $percentualIds = $this->idsFromPercentual();
        }

        if (in_array($source, ['appointments', 'both'], true)) {
            $appointmentIds = $this->idsFromAppointments();
        }

        $this->sourceCounts = [
            'fonte' => $source,
            'nomes_na_grade' => count($gradeNames),
            'ids_agenda' => count($agendaIds),
            'ids_percentual' => count($percentualIds),
            'ids_atendimento' => count($appointmentIds),
        ];

        $query = DB::connection('stg')->table('tb_operador')
            ->select(['operador_id', 'nome', 'conselho', 'ativo', 'medico', 'medico_agenda', 'profissional'])
            ->orderBy('nome');

        if ($source === 'flags') {
            if ((bool) $this->option('only-agenda')) {
                $query->where('medico_agenda', true);
            } else {
                $query->where(fn ($builder) => $builder->where('medico', true)->orWhere('medico_agenda', true)->orWhere('profissional', true));
            }
        } elseif ($source === 'grade') {
            if (! (bool) $this->option('include-inactive')) {
                $query->where('ativo', true);
            }

            $found = [];

            $rows = $query->get()->filter(function (object $row) use ($gradeNames, &$found): bool {
                $normalized = $this->normalize((string) $row->nome);

                if (isset($gradeNames[$normalized])) {
                    $found[$normalized] = true;

                    return true;
                }

                return false;
            });

            $missing = array_diff_key($gradeNames, $found);
            $this->sourceCounts['nomes_sem_operador'] = count($missing);

            if ((bool) $this->option('create-missing')) {
                // O legado não tem registro desses médicos fora do nome na grade
                // (tb_agenda.nome é texto). Criamos o profissional com o nome
                // normalizado — o conselho pode ser preenchido depois na UI.
                foreach ($missing as $name) {
                    $rows->push((object) [
                        'operador_id' => null,
                        'nome' => $name,
                        'conselho' => null,
                        'ativo' => true,
                        'medico' => false,
                        'medico_agenda' => true,
                        'profissional' => true,
                    ]);
                }

                $this->sourceCounts['criados_da_grade'] = count($missing);
            }

            return $rows;
        } else {
            $query->whereIn('operador_id', array_values(array_unique([...$agendaIds, ...$percentualIds, ...$appointmentIds])));
        }

        if (! (bool) $this->option('include-inactive')) {
            $query->where('ativo', true);
        }

        return $query->get();
    }

    /** @return array<string, true> nomes normalizados distintos da grade */
    private function namesFromGrade(): array
    {
        $file = (string) ($this->option('grade-file') ?: rtrim((string) config('sistema_dez.stg_import_path'), '/').'/grade_medicos.csv');

        if (! is_file($file)) {
            throw new RuntimeException("Grade não encontrada: {$file}");
        }

        $handle = fopen($file, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Não foi possível abrir {$file}");
        }

        $header = fgetcsv($handle, escape: '');

        if ($header === false) {
            fclose($handle);

            throw new RuntimeException("CSV vazio: {$file}");
        }

        $header[0] = ltrim((string) $header[0], "\xEF\xBB\xBF");
        $index = array_flip(array_map(static fn (mixed $column): string => trim((string) $column), $header));
        $names = [];

        while (($line = fgetcsv($handle, escape: '')) !== false) {
            $raw = (string) ($line[$index['medico_agenda']] ?? '');
            $normalized = $this->normalize($raw);

            if ($normalized !== '') {
                $display = LegacyNameNormalizer::display($raw);
                $names[$normalized] = $display !== '' ? $display : $normalized;
            }
        }

        fclose($handle);

        return $names;
    }

    private function normalize(string $value): string
    {
        return LegacyNameNormalizer::key($value);
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

    /**
     * Ids dos médicos que ATENDERAM na janela (tb_agenda_exames.medico_agenda).
     *
     * É a fonte mais confiável: o atendimento referencia o operador por id e o
     * nome cadastrado é o completo ("CARLOS JOSE MOTA DE LIMA"), enquanto a
     * grade usa apelidos ("DR. CARLOS MOTA"), que não casam por nome.
     */
    private function idsFromAppointments(): array
    {
        $since = now()->subMonths((int) ($this->option('months') ?: 24))->toDateString();

        return DB::connection('stg')->table('tb_agenda_exames')
            ->whereNotNull('medico_agenda')
            ->where('data', '>=', $since)
            ->distinct()
            ->pluck('medico_agenda')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
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
