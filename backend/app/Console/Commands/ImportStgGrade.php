<?php

namespace App\Console\Commands;

use App\Enums\BookingMode;
use App\Enums\Modality;
use App\Enums\PriceStatus;
use App\Enums\ProcedureType;
use App\Models\Agreement;
use App\Models\CatalogLink;
use App\Models\Procedure;
use App\Models\ProcedurePrice;
use App\Models\Professional;
use App\Models\ScheduleWindow;
use App\Models\ServiceOffering;
use App\Models\Unit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ImportStgGrade extends Command
{
    protected $signature = 'stg:import-grade
        {--source= : Diretório da extração (padrão: STG_IMPORT_PATH)}
        {--procedure= : legacy_id ou nome do procedimento de consulta das ofertas; sem ele, lista candidatos}
        {--agreement= : legacy_id ou nome do convênio (padrão: o convênio padrão)}
        {--unit= : legacy_id ou nome da unidade (padrão: a única unidade operacional)}
        {--online : Habilita as ofertas no agendamento online (padrão: somente interno)}
        {--dry-run : Simula e desfaz a transação}
        {--commit : Confirma o seed}';

    protected $description = 'Semeia uma agenda PROVISÓRIA a partir da grade do STG (grade_medicos.csv), criando vínculos, ofertas e janelas de escala';

    public function handle(): int
    {
        if ((bool) $this->option('dry-run') === (bool) $this->option('commit')) {
            $this->error('Informe exatamente uma opção: --dry-run ou --commit.');

            return self::INVALID;
        }

        $source = $this->option('source') ?: config('sistema_dez.stg_import_path');

        if (! is_string($source) || $source === '') {
            $this->error('Informe --source ou configure STG_IMPORT_PATH.');

            return self::INVALID;
        }

        $file = rtrim($source, '/').'/grade_medicos.csv';

        if (! is_file($file)) {
            $this->error("Arquivo não encontrado: {$file}");

            return self::INVALID;
        }

        $procedureOption = trim((string) ($this->option('procedure') ?: ''));

        if ($procedureOption === '') {
            $this->reportProcedureCandidates();

            return self::INVALID;
        }

        try {
            $procedure = $this->resolveProcedure($procedureOption);
            $agreement = $this->resolveAgreement((string) ($this->option('agreement') ?: ''));
            $unit = $this->resolveUnit((string) ($this->option('unit') ?: ''));
            $rows = $this->readGrade($file);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $commit = (bool) $this->option('commit');
        $online = (bool) $this->option('online');

        $summary = DB::transaction(function () use ($rows, $procedure, $agreement, $unit, $online): array {
            $createdOffers = 0;
            $createdWindows = 0;
            $createdLinks = 0;
            $unmatchedNames = [];
            $inactiveNames = [];
            $withoutPrice = [];
            $byProfessional = [];

            foreach ($rows as $name => $windows) {
                $professional = Professional::query()->where('search_name', $name)->first();

                if (! $professional instanceof Professional) {
                    $unmatchedNames[$name] = count($windows);

                    continue;
                }

                if (! $professional->active) {
                    $inactiveNames[$professional->name] = count($windows);

                    continue;
                }

                $price = $this->validPrice($procedure, $agreement, $unit);

                if ($price === null) {
                    $withoutPrice[$professional->name] = count($windows);

                    continue;
                }

                $link = CatalogLink::query()->firstOrCreate(
                    [
                        'unit_id' => $unit->id,
                        'procedure_id' => $procedure->id,
                        'professional_id' => $professional->id,
                        'agreement_id' => $agreement->id,
                    ],
                    ['active' => true],
                );

                if ($link->wasRecentlyCreated) {
                    $createdLinks++;
                }

                $offering = ServiceOffering::query()->firstOrNew([
                    'unit_id' => $unit->id,
                    'procedure_id' => $procedure->id,
                    'professional_id' => $professional->id,
                    'agreement_id' => $agreement->id,
                ]);

                $isNewOffering = ! $offering->exists;

                $offering->fill([
                    'catalog_link_id' => $link->id,
                    'procedure_price_id' => $price->id,
                    'booking_mode' => BookingMode::FixedSlot,
                    'modality' => Modality::Presential,
                    'price' => $price->amount,
                    'duration_override_minutes' => $this->slotMinutes($windows),
                    'daily_capacity' => null,
                    'arrival_block_minutes' => 60,
                    'arrival_block_capacity' => 3,
                    'online_enabled' => $online,
                    'active' => true,
                ]);
                $offering->save();

                if ($isNewOffering) {
                    $createdOffers++;
                }

                foreach ($windows as $window) {
                    $scheduleWindow = ScheduleWindow::query()->firstOrNew([
                        'service_offering_id' => $offering->id,
                        'weekday' => $window['weekday'],
                        'starts_at' => $window['starts_at'],
                    ]);

                    $isNewWindow = ! $scheduleWindow->exists;

                    $scheduleWindow->fill([
                        'ends_at' => $window['ends_at'],
                        'slot_minutes' => $window['slot_minutes'],
                        'daily_capacity_override' => $window['capacity'],
                        'effective_from' => now()->toDateString(),
                        'effective_to' => now()->addDays(30)->toDateString(),
                        'active' => true,
                    ]);
                    $scheduleWindow->save();

                    if ($isNewWindow) {
                        $createdWindows++;
                    }
                }

                $byProfessional[$professional->name] = count($windows);
            }

            return [
                'links' => $createdLinks,
                'offerings' => $createdOffers,
                'windows' => $createdWindows,
                'linked_professionals' => count($byProfessional),
                'unmatched_names' => $unmatchedNames,
                'inactive_names' => $inactiveNames,
                'without_price' => $withoutPrice,
            ];
        });

        if (! $commit) {
            $this->line(json_encode([...$summary, 'commit' => false], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $this->info('Dry-run concluído; nenhuma alteração persistida.');

            return self::SUCCESS;
        }

        $this->line(json_encode([...$summary, 'commit' => true], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->info('Agenda provisória criada. Rode `php artisan schedule:materialize` para gerar as ocorrências.');

        return self::SUCCESS;
    }

    /** @return array<string, list<array{weekday: int, starts_at: string, ends_at: string, slot_minutes: int, capacity: int|null}>> */
    private function readGrade(string $file): array
    {
        $handle = fopen($file, 'rb');

        if ($handle === false) {
            throw new \RuntimeException("Não foi possível abrir {$file}");
        }

        $header = fgetcsv($handle, escape: '');
        $rows = [];

        if ($header === false) {
            fclose($handle);

            throw new \RuntimeException("CSV vazio: {$file}");
        }

        $header[0] = ltrim($header[0], "\xEF\xBB\xBF");
        $index = array_flip(array_map(static fn (mixed $column): string => trim((string) $column), $header));

        while (($line = fgetcsv($handle, escape: '')) !== false) {
            if ($line === [null] || count($line) < count($index)) {
                continue;
            }

            $name = $this->normalize((string) ($line[$index['medico_agenda']] ?? ''));
            $weekday = (int) ($line[$index['dia']] ?? -1);

            if ($name === '' || $weekday < 0 || $weekday > 6) {
                continue;
            }

            $startsAt = (string) ($line[$index['inicio']] ?? '');
            $endsAt = (string) ($line[$index['fim']] ?? '');

            if ($startsAt === '' || $endsAt === '' || $startsAt >= $endsAt) {
                continue;
            }

            $rows[$name][] = [
                'weekday' => $weekday,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'slot_minutes' => $this->minutes((string) ($line[$index['tempo_consulta']] ?? ''), 10),
                'capacity' => $this->capacity((string) ($line[$index['qtde']] ?? '')),
            ];
        }

        fclose($handle);

        return $rows;
    }

    /** @param list<array{weekday: int, starts_at: string, ends_at: string, slot_minutes: int, capacity: int|null}> $windows */
    private function slotMinutes(array $windows): int
    {
        $counts = [];

        foreach ($windows as $window) {
            $counts[$window['slot_minutes']] = ($counts[$window['slot_minutes']] ?? 0) + 1;
        }

        arsort($counts);

        return (int) array_key_first($counts);
    }

    private function minutes(string $value, int $fallback): int
    {
        $minutes = (int) str_replace(':00', '', trim($value));

        return $minutes > 0 ? $minutes : $fallback;
    }

    private function capacity(string $value): ?int
    {
        $capacity = (int) trim($value);

        return $capacity > 0 ? $capacity : null;
    }

    private function validPrice(Procedure $procedure, Agreement $agreement, Unit $unit): ?ProcedurePrice
    {
        return ProcedurePrice::query()
            ->where('procedure_id', $procedure->id)
            ->where('agreement_id', $agreement->id)
            ->where(fn ($query) => $query->whereNull('unit_id')->orWhere('unit_id', $unit->id))
            ->where('status', PriceStatus::Valid)
            ->where(fn ($query) => $query->whereNull('valid_from')->orWhere('valid_from', '<=', now()->toDateString()))
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>=', now()->toDateString()))
            ->orderBy('amount')
            ->first();
    }

    private function resolveProcedure(string $value): Procedure
    {
        $procedure = Procedure::query()
            ->where('legacy_id', $value)
            ->orWhere('name', $value)
            ->first();

        if (! $procedure instanceof Procedure) {
            throw new \RuntimeException("Procedimento não encontrado: {$value}");
        }

        return $procedure;
    }

    private function resolveAgreement(string $value): Agreement
    {
        if ($value === '') {
            return Agreement::query()->where('is_default', true)->firstOrFail();
        }

        $agreement = Agreement::query()->where('legacy_id', $value)->orWhere('name', $value)->first();

        if (! $agreement instanceof Agreement) {
            throw new \RuntimeException("Convênio não encontrado: {$value}");
        }

        return $agreement;
    }

    private function resolveUnit(string $value): Unit
    {
        if ($value !== '') {
            $unit = Unit::query()->where('legacy_id', $value)->orWhere('name', $value)->first();

            if (! $unit instanceof Unit) {
                throw new \RuntimeException('Unidade não encontrada: '.$value.' | disponíveis: '.$this->unitList());
            }

            if (! $unit->operational_enabled) {
                throw new \RuntimeException('A unidade '.$unit->name.' não está operacional. Habilite-a no cadastro ou use outra. | '.$this->unitList());
            }

            return $unit;
        }

        $unit = Unit::query()->where('operational_enabled', true)->orderBy('name')->first();

        if (! $unit instanceof Unit) {
            throw new \RuntimeException(
                'Nenhuma unidade operacional. Habilite a unidade no cadastro (ou use --unit=<legacy_id|nome>). | '.
                $this->unitList()
            );
        }

        return $unit;
    }

    private function unitList(): string
    {
        return Unit::query()->orderBy('name')->get(['legacy_id', 'name', 'operational_enabled', 'active'])
            ->map(fn (Unit $unit): string => sprintf(
                '%s (legacy_id=%s, operacional=%s, ativa=%s)',
                $unit->name,
                $unit->legacy_id ?? '-',
                $unit->operational_enabled ? 'sim' : 'não',
                $unit->active ? 'sim' : 'não',
            ))
            ->implode(' | ');
    }

    private function reportProcedureCandidates(): void
    {
        $this->warn('Informe --procedure=<legacy_id ou nome>. Candidatos (consultas ativas com preço válido):');

        $agreement = Agreement::query()->where('is_default', true)->first();

        $candidates = Procedure::query()
            ->where('active', true)
            ->where('type', ProcedureType::Consultation)
            ->orderBy('name')
            ->limit(25)
            ->get(['id', 'legacy_id', 'name']);

        foreach ($candidates as $candidate) {
            $price = $agreement instanceof Agreement
                ? ProcedurePrice::query()->where('procedure_id', $candidate->id)->where('agreement_id', $agreement->id)
                    ->where('status', PriceStatus::Valid)->orderBy('amount')->value('amount')
                : null;

            $this->line(sprintf('  legacy_id=%-8s %-60s preço=%s', $candidate->legacy_id ?? '-', mb_substr($candidate->name, 0, 60), $price ?? '-'));
        }
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->ascii()->squish()->upper()->toString();
    }
}
