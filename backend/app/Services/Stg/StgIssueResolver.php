<?php

namespace App\Services\Stg;

use App\Enums\ImportIssueResolutionStatus;
use App\Enums\PriceStatus;
use App\Models\Agreement;
use App\Models\Procedure;
use App\Models\ProcedurePrice;
use App\Models\StgImportIssue;
use App\Models\StgImportRun;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Applies the business decisions recorded in plano_sistema_dez.md to one STG
 * import run. The rules are deliberately explicit and idempotent: unknown
 * issues remain OPEN instead of being guessed or assigned a legacy id.
 */
final class StgIssueResolver
{
    private const DECISION_DATE = '31/08/2026';

    /**
     * @return array<string, mixed>
     */
    public function resolve(?string $runId = null, bool $commit = true): array
    {
        $run = $this->findRun($runId);

        if (! $commit) {
            return $this->plan($run, false);
        }

        return DB::transaction(fn (): array => $this->plan($run, true));
    }

    private function findRun(?string $runId): StgImportRun
    {
        $run = $runId === null
            ? StgImportRun::query()->latest('started_at')->first()
            : StgImportRun::query()->find($runId);

        if (! $run instanceof StgImportRun) {
            throw new RuntimeException('Nenhuma execução de importação STG foi encontrada.');
        }

        return $run;
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(StgImportRun $run, bool $commit): array
    {
        $issues = StgImportIssue::query()
            ->where('import_run_id', $run->getKey())
            ->orderBy('code')
            ->orderBy('entity_type')
            ->orderBy('id')
            ->get();

        $summary = [
            'run_id' => $run->getKey(),
            'commit' => $commit,
            'issues' => [
                'total' => $issues->count(),
                'matched' => 0,
                'changed' => 0,
                'already_in_target' => 0,
                'unrecognized' => 0,
                'conflicts' => 0,
                'by_status' => [],
            ],
            'prices' => [
                'promoted_to_valid' => 0,
                'already_valid' => 0,
                'kept_quarantined' => 0,
            ],
        ];

        foreach ($issues as $issue) {
            $decision = $this->decisionFor($issue);

            if ($decision === null) {
                $summary['issues']['unrecognized']++;

                continue;
            }

            $summary['issues']['matched']++;
            $target = $decision['status'];
            $current = (string) $issue->resolution_status;

            if ($current === $target) {
                $summary['issues']['already_in_target']++;
            } elseif ($current !== ImportIssueResolutionStatus::Open->value) {
                // Never overwrite a human decision made through the API.
                $summary['issues']['conflicts']++;

                continue;
            } elseif ($commit) {
                $issue->update([
                    'resolution_status' => $target,
                    'resolution_note' => $decision['note'],
                    'resolved_by' => null,
                    'resolved_at' => now(),
                ]);
                $summary['issues']['changed']++;
            }

            $summary['issues']['by_status'][$target] = ($summary['issues']['by_status'][$target] ?? 0) + 1;

            if ($issue->code !== 'AMBIGUOUS_PRICE') {
                continue;
            }

            if (! $decision['promote']) {
                $summary['prices']['kept_quarantined'] += $this->countQuarantinedPriceRows($decision);

                continue;
            }

            $priceResult = $this->promotePrice($decision, $commit);
            $summary['prices']['promoted_to_valid'] += $priceResult['promoted'];
            $summary['prices']['already_valid'] += $priceResult['already_valid'];
            $summary['prices']['kept_quarantined'] += $priceResult['quarantined'];
        }

        if ($summary['issues']['conflicts'] > 0) {
            throw new RuntimeException('Há pendências já decididas de forma diferente; nenhuma alteração foi confirmada.');
        }

        return $summary;
    }

    /**
     * @return array{status: string, note: string, promote: bool, procedure_name?: string, agreement_legacy_id?: string, unit_legacy_id?: string|null, amount?: string}
     */
    private function decisionFor(StgImportIssue $issue): ?array
    {
        $status = match (true) {
            $issue->code === 'AMBIGUOUS_CLASSIFICATION' && $issue->entity_type === 'procedure' => ImportIssueResolutionStatus::Ignored->value,
            $issue->code === 'LEGACY_ID_NOT_FOUND' && in_array($issue->entity_type, ['agreement', 'procedure', 'professional'], true) => ImportIssueResolutionStatus::Ignored->value,
            $issue->code === 'CLASSIFICATION_NOT_FOUND' && $issue->entity_type === 'professional' => ImportIssueResolutionStatus::Unmatched->value,
            default => null,
        };

        if ($status !== null) {
            return [
                'status' => $status,
                'note' => $status === ImportIssueResolutionStatus::Unmatched->value
                    ? 'Sem linha correspondente em classif_profissionais.csv; mantido UNMATCHED para correção no cadastro.'
                    : 'Linha de classificação sem entidade correspondente ou duplicata por acento; ignorada sem criar ID legado artificial.',
                'promote' => false,
            ];
        }

        if ($issue->code !== 'AMBIGUOUS_PRICE' || $issue->entity_type !== 'price') {
            return null;
        }

        $payload = is_array($issue->payload) ? $issue->payload : [];
        $procedureName = trim((string) ($payload['procedure_name'] ?? ''));
        $agreementLegacyId = trim((string) ($payload['agreement_legacy_id'] ?? ''));
        $unitLegacyId = array_key_exists('unit_legacy_id', $payload) && $payload['unit_legacy_id'] !== null
            ? trim((string) $payload['unit_legacy_id'])
            : null;
        $amounts = collect(is_array($payload['amounts'] ?? null) ? $payload['amounts'] : [])
            ->map(static fn (mixed $amount): string => number_format((float) $amount, 2, '.', ''))
            ->sort()
            ->values()
            ->implode(',');
        $key = implode('|', [$procedureName, $agreementLegacyId, $unitLegacyId ?? '', $amounts]);

        $decisions = [
            'BIOPSIA PEQUENA|49|1|75.00,120.00' => ['promote' => true, 'amount' => '120.00'],
            'BIOPSIA PEQUENA|49|2|75.00,120.00' => ['promote' => true, 'amount' => '120.00'],
            'US CALCANEO DIREITO|49|1|100.00,120.00' => ['promote' => true, 'amount' => '120.00'],
            'US CALCANEO DIREITO|49|2|100.00,120.00' => ['promote' => true, 'amount' => '120.00'],
            'US TIREOIDE|71||95.00,165.00' => ['promote' => false, 'amount' => null],
        ];

        if (! array_key_exists($key, $decisions)) {
            return null;
        }

        $decision = $decisions[$key];
        $note = $decision['promote']
            ? sprintf('Decisão do dono (%s): manter o maior valor (R$ %s); valores menores permanecem em quarentena.', self::DECISION_DATE, str_replace('.', ',', (string) $decision['amount']))
            : sprintf('Decisão do dono (%s): conflito registrado; os preços permanecem em quarentena até conferência manual.', self::DECISION_DATE);

        return [
            'status' => ImportIssueResolutionStatus::Resolved->value,
            'note' => $note,
            'promote' => (bool) $decision['promote'],
            'procedure_name' => $procedureName,
            'agreement_legacy_id' => $agreementLegacyId,
            'unit_legacy_id' => $unitLegacyId,
            'amount' => $decision['amount'],
        ];
    }

    /**
     * @param  array{procedure_name?: string, agreement_legacy_id?: string, unit_legacy_id?: string|null, amount?: string|null}  $decision
     */
    private function countQuarantinedPriceRows(array $decision): int
    {
        $priceQuery = $this->priceQuery($decision);

        return (clone $priceQuery)->where('status', PriceStatus::Quarantined)->count();
    }

    /**
     * @param  array{procedure_name?: string, agreement_legacy_id?: string, unit_legacy_id?: string|null, amount?: string|null}  $decision
     * @return array{promoted: int, already_valid: int, quarantined: int}
     */
    private function promotePrice(array $decision, bool $commit): array
    {
        $priceQuery = $this->priceQuery($decision);
        $groupQuery = $this->priceQuery($decision, false);
        $quarantined = (clone $priceQuery)->where('status', PriceStatus::Quarantined)->count();
        $valid = (clone $priceQuery)->where('status', PriceStatus::Valid)->count();

        if ($quarantined === 0 && $valid === 0) {
            throw new RuntimeException(sprintf(
                'Preço canônico não encontrado para %s / convênio %s / unidade %s / R$ %s.',
                $decision['procedure_name'] ?? 'procedimento',
                $decision['agreement_legacy_id'] ?? '?',
                $decision['unit_legacy_id'] ?? 'sem unidade',
                $decision['amount'] ?? '?',
            ));
        }

        if (! $commit || $quarantined === 0) {
            return [
                'promoted' => 0,
                'already_valid' => $valid > 0 ? 1 : 0,
                'quarantined' => (clone $groupQuery)->where('status', PriceStatus::Quarantined)->count(),
            ];
        }

        $promoted = (clone $priceQuery)->where('status', PriceStatus::Quarantined)->update([
            'status' => PriceStatus::Valid,
            'updated_at' => now(),
        ]);

        return [
            'promoted' => $promoted,
            'already_valid' => $valid > 0 ? 1 : 0,
            'quarantined' => (clone $groupQuery)->where('status', PriceStatus::Quarantined)->count(),
        ];
    }

    /**
     * @param  array{procedure_name?: string, agreement_legacy_id?: string, unit_legacy_id?: string|null, amount?: string|null}  $decision
     */
    private function priceQuery(array $decision, bool $filterAmount = true): Builder
    {
        $procedure = Procedure::query()->where('name', $decision['procedure_name'] ?? '')->first();
        $agreement = Agreement::query()->where('legacy_id', $decision['agreement_legacy_id'] ?? '')->first();

        if (! $procedure instanceof Procedure || ! $agreement instanceof Agreement) {
            throw new RuntimeException(sprintf(
                'Referência de preço não encontrada para %s / convênio %s.',
                $decision['procedure_name'] ?? 'procedimento',
                $decision['agreement_legacy_id'] ?? '?',
            ));
        }

        $unitId = null;
        if (($decision['unit_legacy_id'] ?? null) !== null) {
            $unit = Unit::query()->where('legacy_id', $decision['unit_legacy_id'])->first();

            if (! $unit instanceof Unit) {
                throw new RuntimeException(sprintf('Unidade legada %s não encontrada.', $decision['unit_legacy_id']));
            }

            $unitId = $unit->getKey();
        }

        return ProcedurePrice::query()
            ->where('procedure_id', $procedure->getKey())
            ->where('agreement_id', $agreement->getKey())
            ->where('unit_id', $unitId)
            ->where('nature', $agreement->legacy_id === '71' ? 'PLAN' : 'PRIVATE')
            ->when($filterAmount && ($decision['amount'] ?? null) !== null, fn ($query) => $query->where('amount', $decision['amount']));
    }
}
