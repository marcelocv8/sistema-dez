<?php

namespace App\Console\Commands;

use App\Enums\BookingMode;
use App\Enums\Modality;
use App\Models\CatalogLink;
use App\Models\Professional;
use App\Models\ScheduleWindow;
use App\Models\ServiceOffering;
use App\Services\Stg\LegacyNameNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class MergeNicknameDoctors extends Command
{
    protected $signature = 'stg:merge-nickname-doctors
        {--dry-run : Simula e desfaz a transação}
        {--commit : Confirma a fusão}';

    protected $description = 'P12 — funde os profissionais criados por APELIDO da grade (legacy_id nulo) nos médicos reais (do atendimento), movendo as janelas de agenda; mantém os que não puderem ser identificados';

    /** @var array<string, list<string>> token => ids de profissionais */
    private array $tokenIndex = [];

    public function handle(): int
    {
        if ((bool) $this->option('dry-run') === (bool) $this->option('commit')) {
            $this->error('Informe exatamente uma opção: --dry-run ou --commit.');

            return self::INVALID;
        }

        $commit = (bool) $this->option('commit');
        $this->tokenIndex = $this->buildTokenIndex();

        DB::beginTransaction();

        try {
            $summary = $this->merge();
            $commit ? DB::commit() : DB::rollBack();
        } catch (Throwable $exception) {
            DB::rollBack();
            $this->error('Falha na fusão: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode([...$summary, 'commit' => $commit], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        if (! $commit) {
            $this->info('Dry-run concluído; nenhuma alteração persistida.');

            return self::SUCCESS;
        }

        $this->info('Fusão concluída. Rode `PHP artisan schedule:materialize` para regenerar as ocorrências.');

        return self::SUCCESS;
    }

    /** @return array<string, int|array<string, string>> */
    private function merge(): array
    {
        $merged = 0;
        $kept = 0;
        $windowsMoved = 0;
        $offersMoved = 0;
        $keptNames = [];

        $nicknames = Professional::query()->withTrashed()->whereNull('legacy_id')->orderBy('name')->get();

        foreach ($nicknames as $nickname) {
            // 1. desfaz o estrago: traz de volta o profissional e a agenda dele
            if ($nickname->trashed()) {
                $nickname->restore();
            }

            $offerings = ServiceOffering::query()->withTrashed()->where('professional_id', $nickname->id)->get();

            foreach ($offerings as $offering) {
                if ($offering->trashed()) {
                    $offering->restore();
                }

                ScheduleWindow::query()->withTrashed()->where('service_offering_id', $offering->id)->restore();
            }

            // 2. tenta identificar o médico real pelo nome
            $real = $this->matchRealProfessional((string) $nickname->name);

            if (! $real instanceof Professional || $real->id === $nickname->id) {
                $kept++;
                $keptNames[$nickname->name] = 'sem correspondente único';

                continue;
            }

            // 3. move as janelas para a oferta do médico real
            foreach ($offerings as $offering) {
                $target = $this->realOfferingFor($offering, $real);

                $moved = ScheduleWindow::query()->withTrashed()
                    ->where('service_offering_id', $offering->id)
                    ->update(['service_offering_id' => $target->id, 'deleted_at' => null]);
                $windowsMoved += $moved;

                $offering->delete();
                $offersMoved++;
            }

            CatalogLink::query()->withTrashed()->where('professional_id', $nickname->id)->get()
                ->each(function (CatalogLink $link): void {
                    $link->delete();
                });

            $nickname->delete();
            $merged++;
        }

        return [
            'apelidos_encontrados' => $nicknames->count(),
            'fundidos_no_medico_real' => $merged,
            'mantidos_sem_correspondencia' => $kept,
            'ofertas_removidas' => $offersMoved,
            'janelas_movidas' => $windowsMoved,
            'detalhe_mantidos' => $keptNames,
        ];
    }

    /** Oferta do médico real para o mesmo (unidade × procedimento × convênio). */
    private function realOfferingFor(ServiceOffering $offering, Professional $real): ServiceOffering
    {
        $attributes = [
            'unit_id' => $offering->unit_id,
            'procedure_id' => $offering->procedure_id,
            'professional_id' => $real->id,
            'agreement_id' => $offering->agreement_id,
        ];

        $existing = ServiceOffering::query()->withTrashed()->where($attributes)->first();

        if ($existing instanceof ServiceOffering) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return $existing;
        }

        $link = CatalogLink::query()->withTrashed()->firstOrNew($attributes);

        if ($link->exists && $link->trashed()) {
            $link->restore();
        }

        if (! $link->exists) {
            $link->fill(['active' => true])->save();
        }

        return ServiceOffering::query()->create([
            ...$attributes,
            'catalog_link_id' => $link->id,
            'procedure_price_id' => $offering->procedure_price_id,
            'booking_mode' => $offering->booking_mode ?: BookingMode::FixedSlot,
            'modality' => $offering->modality ?: Modality::Presential,
            'price' => $offering->price,
            'duration_override_minutes' => $offering->duration_override_minutes,
            'daily_capacity' => $offering->daily_capacity,
            'arrival_block_minutes' => $offering->arrival_block_minutes ?: 60,
            'arrival_block_capacity' => $offering->arrival_block_capacity ?: 3,
            'online_enabled' => false,
            'active' => true,
        ]);
    }

    /**
     * Identifica o médico real: nome exato ou, na falta, TODOS os tokens
     * significativos do apelido presentes em um único cadastro (ex.: "CARLOS
     * MOTA" → "CARLOS JOSE MOTA DE LIMA"). Se houver 0 ou mais de 1 candidato,
     * não arrisca.
     */
    private function matchRealProfessional(string $nickname): ?Professional
    {
        $key = LegacyNameNormalizer::key($nickname);

        if ($key === '') {
            return null;
        }

        $exact = Professional::query()->where('search_name', $key)->whereNotNull('legacy_id')->first();

        if ($exact instanceof Professional) {
            return $exact;
        }

        $tokens = $this->significantTokens($key);

        if ($tokens === []) {
            return null;
        }

        $candidates = null;

        foreach ($tokens as $token) {
            $ids = $this->tokenIndex[$token] ?? [];
            $candidates = $candidates === null ? $ids : array_values(array_intersect($candidates, $ids));

            if ($candidates === []) {
                return null;
            }
        }

        if (count($candidates) !== 1) {
            return null;
        }

        return Professional::query()->find($candidates[0]);
    }

    /** @return array<string, list<string>> */
    private function buildTokenIndex(): array
    {
        $index = [];

        foreach (Professional::query()->whereNotNull('legacy_id')->get(['id', 'search_name']) as $professional) {
            foreach ($this->significantTokens((string) $professional->search_name) as $token) {
                $index[$token][] = $professional->id;
            }
        }

        return $index;
    }

    /** @return list<string> */
    private function significantTokens(string $key): array
    {
        $ignored = ['DE', 'DA', 'DO', 'DAS', 'DOS', 'E', 'JR', 'FILHO', 'NETO', 'SOBRINHO'];

        return Collection::make(explode(' ', $key))
            ->map(static fn (string $token): string => trim($token))
            ->filter(static fn (string $token): bool => mb_strlen($token) >= 4 && ! in_array($token, $ignored, true))
            ->values()
            ->all();
    }
}
