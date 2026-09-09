<?php

namespace App\Services\Stg;

use App\Enums\AsoStatus;
use App\Enums\AsoType;
use App\Models\Agreement;
use App\Models\MtAso;
use App\Models\MtFunction;
use App\Models\MtRisk;
use App\Models\MtSector;
use App\Models\Patient;
use App\Models\StgImportIssue;
use App\Models\StgImportRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class StgMtImporter
{
    /** @var list<string> */
    private const Files = [
        'mt_riscos.csv',
        'mt_setores.csv',
        'mt_funcoes.csv',
        'mt_asos.csv',
    ];

    /** @var list<array{code: string, entity_type: string, legacy_id: ?string, source_file: string, payload: array<string, mixed>}> */
    private array $issues = [];

    /**
     * @return array<string, mixed>
     */
    public function import(string $sourceDirectory, bool $commit, int $createdBy): array
    {
        $sourceDirectory = rtrim($sourceDirectory, '/\\').'/mt';
        $this->assertSourcesExist($sourceDirectory);
        $checksum = $this->combinedChecksum($sourceDirectory);
        $this->issues = [];

        DB::beginTransaction();

        try {
            $run = StgImportRun::create([
                'checksum' => $checksum,
                'status' => 'RUNNING',
                'summary' => [],
                'started_at' => now(),
            ]);

            $summary = $this->performImport($sourceDirectory, $createdBy);
            $summary['checksum'] = $checksum;
            $summary['issues'] = count($this->issues);

            foreach ($this->issues as $issue) {
                StgImportIssue::create(['import_run_id' => $run->id, ...$issue]);
            }

            $run->update([
                'status' => 'COMPLETED',
                'summary' => $summary,
                'completed_at' => now(),
            ]);

            if ($commit) {
                DB::commit();
            } else {
                DB::rollBack();
            }

            return $summary;
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function performImport(string $directory, int $createdBy): array
    {
        $risks = $this->importRisks($this->readCsv($directory.'/mt_riscos.csv'));
        $sectorImport = $this->importSectors($this->readCsv($directory.'/mt_setores.csv'));
        $functionImport = $this->importFunctions($this->readCsv($directory.'/mt_funcoes.csv'), $sectorImport['items']);
        $asoImport = $this->importAsos(
            $this->readCsv($directory.'/mt_asos.csv'),
            $sectorImport['items'],
            $functionImport['items'],
            $createdBy,
        );

        return [
            'risks' => $risks,
            'sectors' => ['created' => $sectorImport['created'], 'updated' => $sectorImport['updated']],
            'functions' => ['created' => $functionImport['created'], 'updated' => $functionImport['updated']],
            ...$asoImport,
        ];
    }

    /** @param list<array<string, string>> $rows @return array<string, int> */
    private function importRisks(array $rows): array
    {
        $created = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $legacyId = $this->nullableLegacyId($row['legacy_id'] ?? null);
            $name = $this->clean((string) ($row['descricao'] ?? ''));

            if ($name === '') {
                $this->issue('MT_RISK_EMPTY_NAME', 'mt_risk', $legacyId, 'mt_riscos.csv', []);

                continue;
            }

            $risk = $legacyId !== null
                ? MtRisk::firstOrNew(['legacy_id' => $legacyId])
                : null;

            $risk ??= MtRisk::firstOrNew(['name' => $name]);
            $wasRecentlyCreated = ! $risk->exists;
            $risk->fill(['name' => $name, 'active' => $this->isActive($row), 'legacy_id' => $legacyId])->save();

            if ($wasRecentlyCreated) {
                $created++;
            } else {
                $updated++;
            }
        }

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array{items: array<int|string, MtSector>, created: int, updated: int}
     */
    private function importSectors(array $rows): array
    {
        $sectorsByLegacyId = [];
        $created = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $legacyId = $this->nullableLegacyId($row['legacy_id'] ?? null);
            $name = $this->clean((string) ($row['descricao'] ?? ''));
            $convenioLegacyId = $this->nullableLegacyId($row['legacy_convenio_id'] ?? null);

            $agreement = $convenioLegacyId !== null
                ? Agreement::where('legacy_id', $convenioLegacyId)->first()
                : null;

            if ($agreement === null) {
                $this->issue('MT_AGREEMENT_NOT_FOUND', 'mt_sector', $legacyId, 'mt_setores.csv', [
                    'legacy_convenio_id' => $convenioLegacyId,
                    'name' => $name,
                ]);

                continue;
            }

            if ($name === '') {
                $this->issue('MT_SECTOR_EMPTY_NAME', 'mt_sector', $legacyId, 'mt_setores.csv', [
                    'legacy_convenio_id' => $convenioLegacyId,
                ]);

                continue;
            }

            $sector = $legacyId !== null
                ? MtSector::firstOrNew(['legacy_id' => $legacyId])
                : null;

            if ($sector === null) {
                $sector = MtSector::firstOrNew(['agreement_id' => $agreement->id, 'name' => $name]);
            }

            $wasRecentlyCreated = ! $sector->exists;
            $sector->fill([
                'agreement_id' => $agreement->id,
                'name' => $name,
                'active' => $this->isActive($row),
                'legacy_id' => $legacyId,
            ])->save();

            if ($legacyId !== null) {
                $sectorsByLegacyId[$legacyId] = $sector;
            }

            if ($wasRecentlyCreated) {
                $created++;
            } else {
                $updated++;
            }
        }

        return ['items' => $sectorsByLegacyId, 'created' => $created, 'updated' => $updated];
    }

    /**
     * @param  array<int|string, MtSector>  $sectors
     * @param  list<array<string, string>>  $rows
     * @return array{items: array<int|string, MtFunction>, created: int, updated: int}
     */
    private function importFunctions(array $rows, array $sectors): array
    {
        $functionsByLegacyId = [];
        $created = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $legacyId = $this->nullableLegacyId($row['legacy_id'] ?? null);
            $name = $this->clean((string) ($row['descricao'] ?? ''));
            $sectorLegacyId = $this->nullableLegacyId($row['legacy_setor_id'] ?? null);
            $riskLegacyId = $this->nullableLegacyId($row['legacy_risco_id'] ?? null);

            $sector = $sectorLegacyId !== null ? ($sectors[$sectorLegacyId] ?? null) : null;

            if ($sector === null) {
                $this->issue('MT_SECTOR_NOT_FOUND', 'mt_function', $legacyId, 'mt_funcoes.csv', [
                    'legacy_setor_id' => $sectorLegacyId,
                    'name' => $name,
                ]);

                continue;
            }

            if ($name === '') {
                $this->issue('MT_FUNCTION_EMPTY_NAME', 'mt_function', $legacyId, 'mt_funcoes.csv', [
                    'legacy_setor_id' => $sectorLegacyId,
                ]);

                continue;
            }

            $risk = $riskLegacyId !== null
                ? MtRisk::where('legacy_id', $riskLegacyId)->first()
                : null;

            $function = $legacyId !== null
                ? MtFunction::firstOrNew(['legacy_id' => $legacyId])
                : null;

            if ($function === null) {
                $function = MtFunction::firstOrNew(['agreement_id' => $sector->agreement_id, 'name' => $name]);
            }

            $wasRecentlyCreated = ! $function->exists;
            $function->fill([
                'agreement_id' => $sector->agreement_id,
                'sector_id' => $sector->id,
                'risk_id' => $risk?->id,
                'name' => $name,
                'active' => $this->isActive($row),
                'legacy_id' => $legacyId,
            ])->save();

            if ($legacyId !== null) {
                $functionsByLegacyId[$legacyId] = $function;
            }

            if ($wasRecentlyCreated) {
                $created++;
            } else {
                $updated++;
            }
        }

        return ['items' => $functionsByLegacyId, 'created' => $created, 'updated' => $updated];
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  array<int|string, MtSector>  $sectors
     * @param  array<int|string, MtFunction>  $functions
     * @return array<string, mixed>
     */
    private function importAsos(array $rows, array $sectors, array $functions, int $createdBy): array
    {
        $created = 0;
        $updated = 0;
        $skippedInactive = 0;

        foreach ($rows as $row) {
            if (! $this->isActive($row)) {
                $skippedInactive++;

                continue;
            }

            $legacyId = $this->nullableLegacyId($row['legacy_id'] ?? null);
            $patientLegacyId = $this->nullableLegacyId($row['legacy_paciente_id'] ?? null);
            $convenioLegacyId = $this->nullableLegacyId($row['legacy_convenio_id'] ?? null);

            $patient = $patientLegacyId !== null
                ? Patient::where('legacy_id', $patientLegacyId)->first()
                : null;

            if ($patient === null) {
                $this->issue('MT_PATIENT_NOT_FOUND', 'mt_aso', $legacyId, 'mt_asos.csv', [
                    'legacy_paciente_id' => $patientLegacyId,
                ]);

                continue;
            }

            $agreement = $convenioLegacyId !== null
                ? Agreement::where('legacy_id', $convenioLegacyId)->first()
                : null;

            if ($agreement === null) {
                $this->issue('MT_AGREEMENT_NOT_FOUND', 'mt_aso', $legacyId, 'mt_asos.csv', [
                    'legacy_convenio_id' => $convenioLegacyId,
                ]);

                continue;
            }

            $posted = $this->parsePostedJson((string) ($row['impressao_aso_json'] ?? ''));
            $sector = $this->resolveSector($row, $posted, $agreement, $sectors);
            $function = $this->resolveFunction($row, $posted, $agreement, $functions, $sector);
            $risks = $this->resolveRisks($row, $posted);

            $type = AsoType::tryFrom(AsoType::LEGACY_MAP[$this->normalize((string) ($row['tipo'] ?? ''))] ?? '');
            if ($type === null) {
                $this->issue('MT_ASO_TYPE_UNKNOWN', 'mt_aso', $legacyId, 'mt_asos.csv', [
                    'tipo' => $row['tipo'] ?? '',
                ]);
                $type = AsoType::Periodico;
            }

            $expiresAt = $this->nullableDate($row['data_validade'] ?? null);
            $realizedAt = $this->nullableDate($row['data_realizacao'] ?? null);

            $aso = $legacyId !== null ? MtAso::firstOrNew(['legacy_id' => $legacyId]) : null;

            if ($aso === null) {
                $this->issue('MT_ASO_NO_LEGACY_ID', 'mt_aso', null, 'mt_asos.csv', [
                    'legacy_paciente_id' => $patientLegacyId,
                ]);

                continue;
            }

            $wasRecentlyCreated = ! $aso->exists;
            $aso->fill([
                'patient_id' => $patient->id,
                'agreement_id' => $agreement->id,
                'sector_id' => $sector?->id,
                'function_id' => $function?->id,
                'type' => $type,
                'status' => $expiresAt !== null ? AsoStatus::Emitido : AsoStatus::Pendente,
                'realized_at' => $realizedAt,
                'expires_at' => $expiresAt,
                'issued_at' => $expiresAt !== null ? now() : null,
                'notes' => $this->nullableClean($row['legacy_exame_nomes'] ?? null),
                'legacy_situacao' => $this->nullableClean($row['legacy_situacao'] ?? null),
                'legacy_id' => $legacyId,
                'created_by' => $createdBy,
            ])->save();

            $aso->risks()->sync($risks->pluck('id')->all());

            if ($wasRecentlyCreated) {
                $created++;
            } else {
                $updated++;
            }
        }

        return ['asos_created' => $created, 'asos_updated' => $updated, 'asos_skipped_inactive' => $skippedInactive];
    }

    /**
     * Riscos vinculados: CSV explícito (legacy_risco_ids) com fallback para o JSON.
     *
     * @return Collection<int, MtRisk>
     */
    private function resolveRisks(array $row, array $posted): Collection
    {
        $legacyIds = [];
        foreach (explode('|', (string) ($row['legacy_risco_ids'] ?? '')) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $legacyIds[] = $part;
            }
        }

        foreach ($posted['riscos'] as $part) {
            $legacyIds[] = (string) $part;
        }

        $risks = Collection::make();

        foreach (array_unique($legacyIds) as $legacyId) {
            $risk = MtRisk::where('legacy_id', (int) $legacyId)->first();

            if ($risk === null) {
                continue;
            }

            $risks->push($risk);
        }

        return $risks->unique('id')->values();
    }

    /** @param array<string, MtSector> $sectors */
    private function resolveSector(array $row, array $posted, Agreement $agreement, array $sectors): ?MtSector
    {
        $legacyId = $this->nullableLegacyId($row['legacy_setor_id'] ?? null)
            ?? $this->nullableLegacyId((string) ($posted['setor'] ?? ''));

        if ($legacyId === null) {
            return null;
        }

        $sector = $legacyId !== null ? ($sectors[(int) $legacyId] ?? null) : null;

        return $sector !== null && $sector->agreement_id === $agreement->id ? $sector : null;
    }

    /** @param array<string, MtFunction> $functions */
    private function resolveFunction(
        array $row,
        array $posted,
        Agreement $agreement,
        array $functions,
        ?MtSector $sector,
    ): ?MtFunction {
        $legacyId = $this->nullableLegacyId($row['legacy_funcao_id'] ?? null)
            ?? $this->nullableLegacyId((string) ($posted['funcao'] ?? ''));

        if ($legacyId === null) {
            return null;
        }

        $function = $functions[(int) $legacyId] ?? null;

        if ($function === null || $function->agreement_id !== $agreement->id) {
            return null;
        }

        if ($sector !== null && $function->sector_id !== null && $function->sector_id !== $sector->id) {
            return null;
        }

        return $function;
    }

    /** @return array<string, mixed> */
    private function parsePostedJson(string $raw): array
    {
        $trimmed = trim($raw);

        if ($trimmed === '' || ! str_starts_with($trimmed, '{')) {
            return ['setor' => null, 'funcao' => null, 'riscos' => []];
        }

        $decoded = json_decode($trimmed, true);

        if (! is_array($decoded)) {
            return ['setor' => null, 'funcao' => null, 'riscos' => []];
        }

        $riscos = $decoded['riscos'] ?? [];
        if (! is_array($riscos)) {
            $riscos = [$riscos];
        }

        return [
            'setor' => $decoded['setor'] ?? $decoded['setor2'] ?? null,
            'funcao' => $decoded['funcao'] ?? $decoded['funcao2'] ?? null,
            'riscos' => array_values(array_filter(array_map(
                static fn ($value): ?string => $value === null ? null : trim((string) $value),
                $riscos,
            ))),
        ];
    }

    private function nullableLegacyId(mixed $value): ?int
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '' || ! ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }

    private function nullableDate(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        $parsed = date_create($value);

        return $parsed ? $parsed->format('Y-m-d') : null;
    }

    private function isActive(array $row): bool
    {
        return in_array(trim((string) ($row['ativo'] ?? '1')), ['1', 't', 'true'], true);
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->ascii()->squish()->upper()->toString();
    }

    private function clean(string $value): string
    {
        return Str::of($value)->squish()->toString();
    }

    private function nullableClean(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->clean((string) $value);
    }

    /** @param array<string, string> $row */
    private function issue(string $code, string $entityType, ?int $legacyId, string $sourceFile, array $payload): void
    {
        $this->issues[] = [
            'code' => $code,
            'entity_type' => $entityType,
            'legacy_id' => $legacyId,
            'source_file' => $sourceFile,
            'payload' => $payload,
        ];
    }

    private function assertSourcesExist(string $directory): void
    {
        foreach (self::Files as $file) {
            if (! is_file($directory.'/'.$file)) {
                throw new RuntimeException("Fonte obrigatória ausente: {$file}");
            }
        }
    }

    private function combinedChecksum(string $directory): string
    {
        $hashes = array_map(
            fn (string $file): string => $file.':'.hash_file('sha256', $directory.'/'.$file),
            self::Files,
        );

        return hash('sha256', implode('|', $hashes));
    }

    /** @return list<array<string, string>> */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Não foi possível ler {$path}.");
        }

        $headers = fgetcsv($handle, escape: '');

        if ($headers === false) {
            fclose($handle);

            return [];
        }

        $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
        $rows = [];

        while (($values = fgetcsv($handle, escape: '')) !== false) {
            if (count($headers) !== count($values)) {
                throw new RuntimeException("Linha CSV inválida em {$path}.");
            }

            $rows[] = array_combine($headers, $values);
        }

        fclose($handle);

        return $rows;
    }
}
