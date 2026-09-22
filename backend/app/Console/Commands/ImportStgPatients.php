<?php

namespace App\Console\Commands;

use App\Models\Agreement;
use App\Models\Patient;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ImportStgPatients extends Command
{
    protected $signature = 'stg:import-patients
        {--only-with-appointments-months=0 : Importa apenas pacientes com atendimento nos últimos N meses (0 = todos)}
        {--limit=0 : Limite de segurança de registros (0 = sem limite)}
        {--include-inactive : Inclui pacientes inativos ou excluídos no legado}
        {--dry-run : Simula e desfaz a transação}
        {--commit : Confirma}';

    protected $description = 'Importa os pacientes do STG (tb_paciente) para patients, com CPF em hash+pepper';

    /** @var array<string, int> */
    private array $issues = [];

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

            $rows = $this->readPatients();
        } catch (Throwable $exception) {
            $this->error('Falha ao ler o legado: '.$exception->getMessage());

            return self::FAILURE;
        }

        $commit = (bool) $this->option('commit');
        $agreements = Agreement::query()->whereNotNull('legacy_id')->pluck('id', 'legacy_id');

        DB::beginTransaction();

        try {
            $summary = $this->persist($rows, $agreements);
            $commit ? DB::commit() : DB::rollBack();
        } catch (Throwable $exception) {
            DB::rollBack();
            $this->error('Falha ao importar pacientes: '.$exception->getMessage());

            return self::FAILURE;
        }

        $payload = [
            'legado_lidos' => $rows->count(),
            ...$summary,
            'sem_cpf' => $this->issues['sem_cpf'] ?? 0,
            'cpf_invalido' => $this->issues['cpf_invalido'] ?? 0,
            'conflito_cpf' => $this->issues['conflito_cpf'] ?? 0,
            'sem_nome' => $this->issues['sem_nome'] ?? 0,
            'convenio_sem_match' => $this->issues['convenio_sem_match'] ?? 0,
            'consentimento' => 'privacy_consent_source=LEGACY_IMPORT (o cadastro físico do STG é a origem)',
            'commit' => $commit,
        ];

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        if (! $commit) {
            $this->info('Dry-run concluído; nenhuma alteração persistida.');

            return self::SUCCESS;
        }

        $this->info('Pacientes importados.');

        return self::SUCCESS;
    }

    /** @return Collection<int, object> */
    private function readPatients(): Collection
    {
        $query = DB::connection('stg')->table('tb_paciente')
            ->select(['paciente_id', 'nome', 'cpf', 'nascimento', 'celular', 'telefone', 'convenio_id', 'ativo', 'desativado', 'data_cadastro'])
            ->orderBy('paciente_id');

        if (! (bool) $this->option('include-inactive')) {
            $query->where('ativo', true)->where(fn ($builder) => $builder->whereNull('desativado')->orWhere('desativado', false));
        }

        $months = (int) $this->option('only-with-appointments-months');

        if ($months > 0) {
            $since = now()->subMonths($months)->toDateString();

            $query->whereIn('paciente_id', function ($sub) use ($since): void {
                $sub->select('paciente_id')->from('tb_agenda_exames')
                    ->whereNotNull('paciente_id')->where('data', '>=', $since);
            });
        }

        $limit = (int) $this->option('limit');

        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /** @param Collection<string, string> $agreements */
    private function persist(Collection $rows, Collection $agreements): array
    {
        $created = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $legacyId = trim((string) $row->paciente_id);
            $name = Str::of((string) $row->nome)->squish()->toString();

            if ($name === '') {
                $this->issue('sem_nome');

                continue;
            }

            $cpf = preg_replace('/\D/', '', (string) $row->cpf) ?? '';
            $cpfHash = null;

            if ($cpf !== '') {
                if (mb_strlen($cpf) !== 11) {
                    $this->issue('cpf_invalido');
                } else {
                    $cpfHash = hash_hmac('sha256', $cpf, (string) config('sistema_dez.identity_pepper'));
                }
            } else {
                $this->issue('sem_cpf');
            }

            $patient = Patient::query()->withTrashed()->where('legacy_id', $legacyId)->first();

            if ($patient === null && $cpfHash !== null) {
                $other = Patient::query()->withTrashed()->where('cpf_hash', $cpfHash)->first();

                if ($other !== null) {
                    // CPF já pertence a outro cadastro: não sobrescrever.
                    $this->issue('conflito_cpf');

                    continue;
                }
            }

            $agreementId = null;

            if ($row->convenio_id !== null) {
                $agreementId = $agreements[(string) $row->convenio_id] ?? null;

                if ($agreementId === null) {
                    $this->issue('convenio_sem_match');
                }
            }

            $attributes = [
                'name' => $name,
                'cpf_hash' => $cpfHash,
                'cpf_last_four' => $cpfHash === null ? null : mb_substr($cpf, -4),
                'birth_date' => $this->date($row->nascimento),
                'phone' => $this->phone($row->celular, $row->telefone),
                'email' => null,
                'default_agreement_id' => $agreementId,
                'privacy_consent_at' => $row->data_cadastro ?: now(),
                'privacy_consent_source' => 'LEGACY_IMPORT',
            ];

            if ($patient instanceof Patient) {
                $patient->fill($attributes)->save();
                $updated++;

                continue;
            }

            Patient::query()->create([...$attributes, 'legacy_id' => $legacyId]);
            $created++;
        }

        return ['criados' => $created, 'atualizados' => $updated];
    }

    private function date(mixed $value): ?string
    {
        $clean = trim((string) $value);

        return $clean === '' ? null : substr($clean, 0, 10);
    }

    private function phone(mixed $celular, mixed $telefone): ?string
    {
        foreach ([$celular, $telefone] as $candidate) {
            $clean = Str::of((string) $candidate)->squish()->toString();

            if ($clean !== '') {
                return $clean;
            }
        }

        return null;
    }

    private function issue(string $code): void
    {
        $this->issues[$code] = ($this->issues[$code] ?? 0) + 1;
    }
}
