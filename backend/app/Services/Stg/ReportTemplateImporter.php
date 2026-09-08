<?php

namespace App\Services\Stg;

use App\Models\Procedure;
use App\Models\Professional;
use App\Models\ReportTemplate;

class ReportTemplateImporter
{
    /** @return array{created:int,updated:int,skipped:int,linked_doctors:int,linked_procedures:int,unmatched_doctors:int,unmatched_procedures:int} */
    public function import(string $dir, bool $commit, bool $refresh = false): array
    {
        $indexFiles = [
            ReportTemplate::TYPE_REPORT => ['INDEX_MODELOS.json', 'laudos_templates.jsonl'],
            ReportTemplate::TYPE_LINE => ['INDEX_LINHAS.json', 'laudos_line_templates.jsonl'],
        ];

        $summary = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'linked_doctors' => 0, 'linked_procedures' => 0, 'unmatched_doctors' => 0, 'unmatched_procedures' => 0];

        foreach ($indexFiles as $legacyType => [$indexName, $jsonlName]) {
            $indexPath = $dir.DIRECTORY_SEPARATOR.$indexName;
            $jsonlPath = $dir.DIRECTORY_SEPARATOR.$jsonlName;
            if (! is_file($indexPath) || ! is_file($jsonlPath)) {
                throw new \RuntimeException("Fontes ausentes em {$dir}: {$indexName} e/ou {$jsonlName}");
            }

            $contents = [];
            $handle = fopen($jsonlPath, 'r');
            try {
                while (($line = fgets($handle)) !== false) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    $contents[(int) $row['id']] = (string) $row['content'];
                }
            } finally {
                fclose($handle);
            }

            foreach (json_decode((string) file_get_contents($indexPath), true, 512, JSON_THROW_ON_ERROR) as $entry) {
                $legacyId = (int) $entry['id'];
                $nome = trim((string) ($entry['nome'] ?? ''));
                $content = $contents[$legacyId] ?? null;

                if ($content === null || $content === '' || $nome === '') {
                    $summary['skipped']++;

                    continue;
                }

                $doctorId = $this->resolveDoctor((string) ($entry['medico'] ?? ''));
                $procedureId = $this->resolveProcedure((string) ($entry['procedimento'] ?? ''));
                if ($doctorId !== null) {
                    $summary['linked_doctors']++;
                } else {
                    $summary['unmatched_doctors']++;
                }
                if ($procedureId !== null) {
                    $summary['linked_procedures']++;
                } else {
                    $summary['unmatched_procedures']++;
                }

                $existing = ReportTemplate::query()->where('legacy_type', $legacyType)->where('legacy_id', $legacyId)->first();
                if ($existing === null) {
                    if ($commit) {
                        ReportTemplate::query()->create([
                            'legacy_type' => $legacyType,
                            'legacy_id' => $legacyId,
                            'legacy_medico' => $entry['medico'] ?? null,
                            'legacy_procedure' => $entry['procedimento'] ?? null,
                            'nome' => $nome,
                            'content_html' => $content,
                            'doctor_id' => $doctorId,
                            'procedure_id' => $procedureId,
                            'active' => true,
                            'imported_at' => now(),
                        ]);
                    }
                    $summary['created']++;

                    continue;
                }

                $touch = [];
                if ($refresh) {
                    $touch['content_html'] = $content;
                }
                if ($existing->doctor_id === null && $doctorId !== null) {
                    $touch['doctor_id'] = $doctorId;
                }
                if ($existing->procedure_id === null && $procedureId !== null) {
                    $touch['procedure_id'] = $procedureId;
                }
                if ($touch !== []) {
                    if ($commit) {
                        $existing->fill($touch)->save();
                    }
                    $summary['updated']++;

                    continue;
                }
                $summary['skipped']++;
            }
        }

        return $summary;
    }

    private function resolveDoctor(string $name): ?string
    {
        $name = trim($name);
        if ($name === '' || strcasecmp($name, 'ADMINISTRADOR') === 0) {
            return null;
        }

        return Professional::query()->whereRaw('lower(name) = lower(?)', [$name])->value('id');
    }

    private function resolveProcedure(string $name): ?string
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $candidates = [$name];
        if (preg_match('/^\d+[A-Z]?\s*-\s*(.+)$/u', $name, $matches) === 1) {
            $candidates[] = trim($matches[1]);
        }

        foreach ($candidates as $candidate) {
            $id = Procedure::query()->whereRaw('lower(name) = lower(?)', [$candidate])->value('id');
            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }
}
