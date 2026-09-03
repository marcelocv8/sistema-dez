<?php

namespace App\Services\Stg;

use App\Enums\PriceStatus;
use App\Enums\ProcedureType;
use App\Models\Agreement;
use App\Models\Procedure;
use App\Models\ProcedurePrice;
use App\Models\Professional;
use App\Models\StgImportIssue;
use App\Models\StgImportRun;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class StgCatalogImporter
{
    /** @var list<string> */
    private const Files = [
        'dez_api_consulta.json',
        'dez_api_exame.json',
        'dez_profissionais.json',
        'dez_convenios.csv',
        'classif_convenios.csv',
        'classif_procedimentos.csv',
        'classif_profissionais.csv',
        'dez_precos_particular.csv',
        'dez_precos_soudez71.csv',
    ];

    /** @var list<array{code: string, entity_type: string, legacy_id: ?string, source_file: string, payload: array<string, mixed>}> */
    private array $issues = [];

    /**
     * @return array<string, mixed>
     */
    public function import(string $sourceDirectory, bool $commit): array
    {
        $sourceDirectory = rtrim($sourceDirectory, '/\\');
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

            $summary = $this->performImport($sourceDirectory);
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
    private function performImport(string $directory): array
    {
        $procedureClassifications = $this->classificationMap($this->readCsv($directory.'/classif_procedimentos.csv'));
        $agreementClassifications = $this->classificationMap($this->readCsv($directory.'/classif_convenios.csv'));
        $professionalClassifications = $this->classificationMap($this->readCsv($directory.'/classif_profissionais.csv'));

        $units = $this->importUnits($this->readCsv($directory.'/dez_precos_particular.csv'));
        $agreements = $this->importAgreements(
            $this->readCsv($directory.'/dez_convenios.csv'),
            $agreementClassifications,
        );
        $procedures = $this->importProcedures(
            $this->readJson($directory.'/dez_api_consulta.json'),
            $this->readJson($directory.'/dez_api_exame.json'),
            $procedureClassifications,
        );
        $professionals = $this->importProfessionals(
            $this->readJson($directory.'/dez_profissionais.json'),
            $professionalClassifications,
        );

        $priceSummary = $this->importPrices(
            $directory,
            $procedures,
            $agreements,
            $units,
        );

        return [
            'source_records' => [
                'procedures' => count($procedures),
                'agreements' => count($agreements),
                'professionals' => count($professionals),
                'particular_prices' => count($this->readCsv($directory.'/dez_precos_particular.csv')),
                'sou_dez_prices' => count($this->readCsv($directory.'/dez_precos_soudez71.csv')),
            ],
            'catalog' => [
                'procedures' => Procedure::whereNotNull('legacy_id')->count(),
                'active_procedures' => Procedure::where('classification_status', 'ATIVO')->count(),
                'agreements' => Agreement::whereNotNull('legacy_id')->count(),
                'active_agreements' => Agreement::where('classification_status', 'ATIVO')->count(),
                'professionals' => Professional::whereNotNull('legacy_id')->count(),
                'active_professionals' => Professional::where('classification_status', 'ATIVO')->count(),
                'units' => Unit::whereNotNull('legacy_id')->count(),
            ],
            'prices' => $priceSummary,
        ];
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array<string, Unit>
     */
    private function importUnits(array $rows): array
    {
        $units = [];

        foreach ($rows as $row) {
            $legacyId = trim($row['cod_empresa']);
            $units[$legacyId] = Unit::updateOrCreate(
                ['legacy_id' => $legacyId],
                ['name' => $this->clean($row['empresa']), 'active' => true],
            );
        }

        return $units;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  array<string, list<array<string, string>>>  $classifications
     * @return array<string, Agreement>
     */
    private function importAgreements(array $rows, array $classifications): array
    {
        $agreements = [];
        $knownNames = [];

        foreach ($rows as $row) {
            $legacyId = trim($row['id']);
            $name = $this->clean($row['nome']);
            $normalizedName = $this->normalize($name);
            $knownNames[$normalizedName] = true;
            $classification = $classifications[$normalizedName][0] ?? null;
            $status = $classification['status'] ?? 'UNMATCHED';

            if ($classification === null) {
                $this->issue('CLASSIFICATION_NOT_FOUND', 'agreement', $legacyId, 'classif_convenios.csv', ['name' => $name]);
            }

            $agreements[$legacyId] = Agreement::updateOrCreate(
                ['legacy_id' => $legacyId],
                [
                    'name' => $name,
                    'search_name' => $normalizedName,
                    'type' => $legacyId === '49' ? 'PRIVATE' : 'AGREEMENT',
                    'is_default' => $legacyId === '49',
                    'online_enabled' => false,
                    'active' => $status === 'ATIVO',
                    'classification_status' => $status,
                ],
            );
        }

        foreach ($classifications as $normalizedName => $matches) {
            if (! isset($knownNames[$normalizedName])) {
                foreach ($matches as $match) {
                    $this->issue('LEGACY_ID_NOT_FOUND', 'agreement', null, 'classif_convenios.csv', $match);
                }
            }
        }

        return $agreements;
    }

    /**
     * @param  list<array<string, mixed>>  $consultations
     * @param  list<array<string, mixed>>  $exams
     * @param  array<string, list<array<string, string>>>  $classifications
     * @return array<string, Procedure>
     */
    private function importProcedures(array $consultations, array $exams, array $classifications): array
    {
        $procedures = [];
        $knownNames = [];
        $typedRows = [
            [ProcedureType::Consultation, $consultations],
            [ProcedureType::Exam, $exams],
        ];

        foreach ($typedRows as [$type, $rows]) {
            foreach ($rows as $row) {
                $legacyId = trim((string) $row['cod_procedimento']);
                $name = $this->clean((string) $row['nome']);
                $normalizedName = $this->normalize($name);
                $knownNames[$normalizedName] = true;
                $matches = $classifications[$normalizedName] ?? [];
                $classification = $matches[0] ?? null;
                $status = $classification['status'] ?? 'UNMATCHED';

                if ($classification === null) {
                    $this->issue('CLASSIFICATION_NOT_FOUND', 'procedure', $legacyId, 'classif_procedimentos.csv', ['name' => $name]);
                } elseif (count($matches) > 1) {
                    $this->issue('AMBIGUOUS_CLASSIFICATION', 'procedure', $legacyId, 'classif_procedimentos.csv', ['matches' => $matches]);
                }

                $procedures[$legacyId] = Procedure::updateOrCreate(
                    ['legacy_id' => $legacyId],
                    [
                        'name' => $name,
                        'search_name' => $normalizedName,
                        'type' => $type,
                        'group_name' => $this->clean($classification['grupo'] ?? (string) $row['grupo']),
                        'tuss_code' => $this->nullableClean($classification['tuss'] ?? null),
                        'active' => $status === 'ATIVO',
                        'legacy' => $status !== 'ATIVO',
                        'online_enabled' => false,
                        'classification_status' => $status,
                    ],
                );
            }
        }

        $this->reportClassificationsWithoutLegacyId(
            $classifications,
            $knownNames,
            'procedure',
            'classif_procedimentos.csv',
        );

        return $procedures;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, list<array<string, string>>>  $classifications
     * @return array<string, Professional>
     */
    private function importProfessionals(array $rows, array $classifications): array
    {
        $professionals = [];
        $knownNames = [];

        foreach ($rows as $row) {
            $legacyId = trim((string) $row['cod_profissional']);
            $name = $this->clean((string) $row['nome_profissional']);
            $normalizedName = $this->normalize($name);
            $knownNames[$normalizedName] = true;
            $classification = $classifications[$normalizedName][0] ?? null;
            $status = $classification['status'] ?? 'UNMATCHED';

            if ($classification === null) {
                $this->issue('CLASSIFICATION_NOT_FOUND', 'professional', $legacyId, 'classif_profissionais.csv', ['name' => $name]);
            }

            $professionals[$legacyId] = Professional::updateOrCreate(
                ['legacy_id' => $legacyId],
                [
                    'name' => $name,
                    'search_name' => $normalizedName,
                    'council_acronym' => $this->nullableClean($row['sigla_conselho'] ?? null),
                    'council_number' => $this->nullableClean($row['conselho_profissional'] ?? null),
                    'active' => $status === 'ATIVO',
                    'online_enabled' => false,
                    'classification_status' => $status,
                ],
            );
        }

        $this->reportClassificationsWithoutLegacyId(
            $classifications,
            $knownNames,
            'professional',
            'classif_profissionais.csv',
        );

        return $professionals;
    }

    /**
     * @param  array<string, list<array<string, string>>>  $classifications
     * @param  array<string, bool>  $knownNames
     */
    private function reportClassificationsWithoutLegacyId(
        array $classifications,
        array $knownNames,
        string $entityType,
        string $source,
    ): void {
        foreach ($classifications as $normalizedName => $matches) {
            if (isset($knownNames[$normalizedName])) {
                continue;
            }

            foreach ($matches as $match) {
                $this->issue('LEGACY_ID_NOT_FOUND', $entityType, null, $source, $match);
            }
        }
    }

    /**
     * @param  array<string, Procedure>  $procedures
     * @param  array<string, Agreement>  $agreements
     * @param  array<string, Unit>  $units
     * @return array<string, int>
     */
    private function importPrices(string $directory, array $procedures, array $agreements, array $units): array
    {
        $keys = [];
        $valid = 0;
        $quarantined = 0;
        $collapsed = 0;

        $particularRows = $this->readCsv($directory.'/dez_precos_particular.csv');
        $particularGroups = $this->groupPrices($particularRows, fn (array $row): string => implode('|', [
            trim($row['cod_procedimento']),
            trim($row['cod_empresa']),
        ]));

        foreach ($particularGroups as $rows) {
            $result = $this->storePriceGroup(
                rows: $rows,
                amountColumn: 'valor_particular',
                procedure: $procedures[trim($rows[0]['cod_procedimento'])] ?? null,
                agreement: $agreements['49'] ?? null,
                unit: $units[trim($rows[0]['cod_empresa'])] ?? null,
                nature: 'PRIVATE',
                sourceFile: 'dez_precos_particular.csv',
                keys: $keys,
            );
            $valid += $result['valid'];
            $quarantined += $result['quarantined'];
            $collapsed += count($rows) - count(array_unique(array_column($rows, 'valor_particular')));
        }

        $souRows = $this->readCsv($directory.'/dez_precos_soudez71.csv');
        $souGroups = $this->groupPrices($souRows, fn (array $row): string => trim($row['cod_procedimento']));

        foreach ($souGroups as $rows) {
            $result = $this->storePriceGroup(
                rows: $rows,
                amountColumn: 'valor_plano',
                procedure: $procedures[trim($rows[0]['cod_procedimento'])] ?? null,
                agreement: $agreements['71'] ?? null,
                unit: null,
                nature: 'PLAN',
                sourceFile: 'dez_precos_soudez71.csv',
                keys: $keys,
            );
            $valid += $result['valid'];
            $quarantined += $result['quarantined'];
            $collapsed += count($rows) - count(array_unique(array_column($rows, 'valor_plano')));
        }

        if ($keys !== []) {
            ProcedurePrice::whereIn('source_file', ['dez_precos_particular.csv', 'dez_precos_soudez71.csv'])
                ->whereNotIn('source_key', $keys)
                ->delete();
        }

        return [
            'valid' => $valid,
            'quarantined_candidates' => $quarantined,
            'identical_rows_collapsed' => $collapsed,
            'stored' => ProcedurePrice::whereIn('source_file', ['dez_precos_particular.csv', 'dez_precos_soudez71.csv'])->count(),
        ];
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  array<int, string>  $keys
     * @return array{valid: int, quarantined: int}
     */
    private function storePriceGroup(
        array $rows,
        string $amountColumn,
        ?Procedure $procedure,
        ?Agreement $agreement,
        ?Unit $unit,
        string $nature,
        string $sourceFile,
        array &$keys,
    ): array {
        $legacyId = trim($rows[0]['cod_procedimento']);

        if ($procedure === null || $agreement === null) {
            $this->issue('PRICE_REFERENCE_NOT_FOUND', 'price', $legacyId, $sourceFile, ['rows' => $rows]);

            return ['valid' => 0, 'quarantined' => 0];
        }

        $amounts = array_values(array_unique(array_map(
            fn (array $row): string => number_format((float) $row[$amountColumn], 2, '.', ''),
            $rows,
        )));
        sort($amounts, SORT_NATURAL);
        $status = count($amounts) === 1 ? PriceStatus::Valid : PriceStatus::Quarantined;

        if ($status === PriceStatus::Quarantined) {
            $this->issue('AMBIGUOUS_PRICE', 'price', $legacyId, $sourceFile, [
                'procedure_name' => $procedure->name,
                'agreement_legacy_id' => $agreement->legacy_id,
                'unit_legacy_id' => $unit?->legacy_id,
                'amounts' => $amounts,
            ]);
        }

        foreach ($amounts as $amount) {
            $sourceKey = hash('sha256', implode('|', [
                $procedure->legacy_id,
                $agreement->legacy_id,
                $unit?->legacy_id ?? 'NO_UNIT',
                $nature,
                $amount,
                $status->value,
            ]));
            $keys[] = $sourceKey;

            ProcedurePrice::updateOrCreate(
                ['source_key' => $sourceKey],
                [
                    'procedure_id' => $procedure->id,
                    'agreement_id' => $agreement->id,
                    'unit_id' => $unit?->id,
                    'nature' => $nature,
                    'amount' => $amount,
                    'status' => $status,
                    'source_file' => $sourceFile,
                    'source_checksum' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)),
                ],
            );
        }

        return $status === PriceStatus::Valid
            ? ['valid' => 1, 'quarantined' => 0]
            : ['valid' => 0, 'quarantined' => count($amounts)];
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array<string, list<array<string, string>>>
     */
    private function classificationMap(array $rows): array
    {
        $map = [];

        foreach ($rows as $row) {
            $map[$this->normalize($row['nome'])][] = $row;
        }

        return $map;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  callable(array<string, string>): string  $key
     * @return array<string, list<array<string, string>>>
     */
    private function groupPrices(array $rows, callable $key): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $groups[$key($row)][] = $row;
        }

        return $groups;
    }

    /** @return list<array<string, mixed>> */
    private function readJson(string $path): array
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Não foi possível ler {$path}.");
        }

        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException("JSON inválido em {$path}.");
        }

        return $decoded;
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

    /** @param array<string, mixed> $payload */
    private function issue(string $code, string $entityType, ?string $legacyId, string $sourceFile, array $payload): void
    {
        $this->issues[] = [
            'code' => $code,
            'entity_type' => $entityType,
            'legacy_id' => $legacyId,
            'source_file' => $sourceFile,
            'payload' => $payload,
        ];
    }
}
