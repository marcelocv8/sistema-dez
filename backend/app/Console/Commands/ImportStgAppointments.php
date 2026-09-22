<?php

namespace App\Console\Commands;

use App\Enums\BookingMode;
use App\Enums\Modality;
use App\Enums\PriceStatus;
use App\Models\Agreement;
use App\Models\Appointment;
use App\Models\CatalogLink;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\ProcedurePrice;
use App\Models\Professional;
use App\Models\ServiceOffering;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Throwable;

class ImportStgAppointments extends Command
{
    protected $signature = 'stg:import-appointments
        {--months=24 : Janela de histórico (meses)}
        {--unit= : Unidade (legacy_id ou nome; padrão: a operacional)}
        {--create-offerings : Cria a oferta (unidade × procedimento × médico × convênio) que faltar}
        {--skip-cancelled : Não importa atendimentos cancelados}
        {--limit=0 : Limite de registros (0 = sem limite)}
        {--dry-run : Simula e desfaz a transação}
        {--commit : Confirma}';

    protected $description = 'Importa os atendimentos do STG (tb_agenda_exames) dos últimos N meses';

    /** @var array<string, int> */
    private array $issues = [];

    /** @var array<string, ServiceOffering|null> */
    private array $offeringCache = [];

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
            $unit = $this->resolveUnit((string) ($this->option('unit') ?: ''));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $commit = (bool) $this->option('commit');

        DB::beginTransaction();

        try {
            DB::connection('stg')->statement('SET default_transaction_read_only = on');

            $summary = $this->import($unit);

            $commit ? DB::commit() : DB::rollBack();
        } catch (Throwable $exception) {
            DB::rollBack();
            $this->error('Falha ao importar atendimentos: '.$exception->getMessage());

            return self::FAILURE;
        }

        $payload = [
            'unidade' => $unit->name,
            'meses' => (int) $this->option('months'),
            ...$summary,
            ...$this->issues,
            'commit' => $commit,
        ];

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        if (! $commit) {
            $this->info('Dry-run concluído; nenhuma alteração persistida.');

            return self::SUCCESS;
        }

        $this->info('Atendimentos importados.');

        return self::SUCCESS;
    }

    /** @return array<string, int> */
    private function import(Unit $unit): array
    {
        $professionals = Professional::query()->whereNotNull('legacy_id')->pluck('id', 'legacy_id');
        $procedures = Procedure::query()->whereNotNull('legacy_id')->pluck('id', 'legacy_id');
        $agreements = Agreement::query()->whereNotNull('legacy_id')->pluck('id', 'legacy_id');
        $patients = Patient::query()->withTrashed()->whereNotNull('legacy_id')->pluck('id', 'legacy_id');
        $defaultAgreement = Agreement::query()->where('is_default', true)->value('id');
        $prices = $this->priceMap();

        $since = now()->subMonths((int) ($this->option('months') ?: 24))->toDateString();
        $skipCancelled = (bool) $this->option('skip-cancelled');
        $created = 0;
        $updated = 0;
        $processed = 0;
        $limit = (int) $this->option('limit');

        $query = DB::connection('stg')->table('tb_agenda_exames')
            ->select([
                'agenda_exames_id', 'paciente_id', 'procedimento_tuss_id', 'medico_agenda', 'convenio_id',
                'data', 'inicio', 'valor', 'valor_total', 'cancelada', 'faltou_manual', 'status_faltou',
                'atendimento', 'realizada', 'retorno', 'agendamento_online', 'internet',
            ])
            ->where('data', '>=', $since)
            ->orderBy('agenda_exames_id');

        if ($skipCancelled) {
            $query->where(fn ($builder) => $builder->whereNull('cancelada')->orWhere('cancelada', false));
        }

        $query->chunk(500, function (Collection $rows) use (
            $unit, $professionals, $procedures, $agreements, $patients, $defaultAgreement, $prices,
            &$created, &$updated, &$processed, $limit
        ): bool {
            foreach ($rows as $row) {
                if ($limit > 0 && $processed >= $limit) {
                    return false;
                }

                $processed++;

                $patientId = $patients[(string) $row->paciente_id] ?? null;
                $professionalId = $professionals[(string) $row->medico_agenda] ?? null;
                $procedureId = $procedures[(string) $row->procedimento_tuss_id] ?? null;
                $agreementId = $agreements[(string) $row->convenio_id] ?? $defaultAgreement;

                if ($patientId === null) {
                    $this->issue('paciente_nao_importado');

                    continue;
                }

                if ($professionalId === null) {
                    $this->issue('medico_nao_importado');

                    continue;
                }

                if ($procedureId === null) {
                    $this->issue('procedimento_nao_importado');

                    continue;
                }

                if ($agreementId === null) {
                    $this->issue('convenio_sem_padrao');

                    continue;
                }

                $price = $this->number($row->valor_total) ?? $this->number($row->valor) ?? 0.0;

                $offering = $this->offeringFor($unit, $procedureId, $professionalId, $agreementId, $prices, $price);

                if ($offering === null) {
                    $this->issue('oferta_ausente_sem_create_offerings');

                    continue;
                }

                $scheduledAt = $this->scheduledAt($row->data, $row->inicio);

                if ($scheduledAt === null) {
                    $this->issue('data_invalida');

                    continue;
                }

                $key = Uuid::uuid5(Uuid::NAMESPACE_URL, 'dez:stg-appointment:'.$row->agenda_exames_id)->toString();
                $appointment = Appointment::query()->withTrashed()->firstOrNew(['idempotency_key' => $key]);
                $isNew = ! $appointment->exists;

                $appointment->fill([
                    'patient_id' => $patientId,
                    'service_offering_id' => $offering->id,
                    'scheduled_at' => $scheduledAt,
                    'status' => $this->status($row),
                    'kind' => $row->retorno ? 'RETURN' : 'NEW',
                    'origin' => ($row->agendamento_online || $row->internet) ? 'ONLINE' : 'RECEPTION',
                    'price_snapshot' => $price,
                ]);
                $appointment->idempotency_key = $key;
                $appointment->save();

                $isNew ? $created++ : $updated++;
            }

            return true;
        });

        return ['lidos' => $processed, 'criados' => $created, 'atualizados' => $updated];
    }

    /** @param Collection<string, float> $prices */
    private function offeringFor(Unit $unit, string $procedureId, string $professionalId, string $agreementId, Collection $prices, float $price): ?ServiceOffering
    {
        $cacheKey = $procedureId.'|'.$professionalId.'|'.$agreementId;

        if (array_key_exists($cacheKey, $this->offeringCache)) {
            return $this->offeringCache[$cacheKey];
        }

        $attributes = [
            'unit_id' => $unit->id,
            'procedure_id' => $procedureId,
            'professional_id' => $professionalId,
            'agreement_id' => $agreementId,
        ];

        $offering = ServiceOffering::query()->where($attributes)->first();

        if ($offering === null && ! (bool) $this->option('create-offerings')) {
            return $this->offeringCache[$cacheKey] = null;
        }

        if ($offering === null) {
            $link = CatalogLink::query()->firstOrCreate($attributes, ['active' => true]);
            $reference = $prices[$procedureId.'|'.$agreementId] ?? null;

            $offering = ServiceOffering::query()->create([
                ...$attributes,
                'catalog_link_id' => $link->id,
                'procedure_price_id' => null,
                'booking_mode' => BookingMode::FixedSlot,
                'modality' => Modality::Presential,
                'price' => $reference ?? $price,
                'arrival_block_minutes' => 60,
                'arrival_block_capacity' => 3,
                'online_enabled' => false,
                'active' => true,
            ]);

            $this->issue('ofertas_criadas');
        }

        return $this->offeringCache[$cacheKey] = $offering;
    }

    /** @return Collection<string, float> */
    private function priceMap(): Collection
    {
        return ProcedurePrice::query()
            ->where('status', PriceStatus::Valid)
            ->orderBy('amount')
            ->get(['procedure_id', 'agreement_id', 'amount'])
            ->mapWithKeys(static fn (ProcedurePrice $price): array => [
                $price->procedure_id.'|'.$price->agreement_id => (float) $price->amount,
            ]);
    }

    private function status(object $row): string
    {
        if ($row->cancelada) {
            return 'CANCELLED';
        }

        if ($row->faltou_manual || $row->status_faltou) {
            return 'NO_SHOW';
        }

        if ($row->atendimento || $row->realizada) {
            return 'DONE';
        }

        return 'CONFIRMED';
    }

    private function scheduledAt(mixed $date, mixed $time): ?CarbonImmutable
    {
        $datePart = trim((string) $date);
        $timePart = trim((string) $time);

        if ($datePart === '' || $timePart === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($datePart.' '.$timePart, config('sistema_dez.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    private function number(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return (float) $value;
    }

    private function resolveUnit(string $value): Unit
    {
        if ($value !== '') {
            $unit = Unit::query()->where('legacy_id', $value)->orWhere('name', $value)->first();

            if (! $unit instanceof Unit) {
                throw new \RuntimeException('Unidade não encontrada: '.$value);
            }

            return $unit;
        }

        $unit = Unit::query()->where('operational_enabled', true)->orderBy('name')->first();

        if (! $unit instanceof Unit) {
            throw new \RuntimeException('Nenhuma unidade operacional. Habilite uma no cadastro ou use --unit=');
        }

        return $unit;
    }

    private function issue(string $code): void
    {
        $this->issues[$code] = ($this->issues[$code] ?? 0) + 1;
    }
}
