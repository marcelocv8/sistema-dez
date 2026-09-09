<?php

namespace Database\Seeders;

use App\Actions\Scheduling\MaterializeScheduleOccurrences;
use App\Enums\AsoStatus;
use App\Enums\AsoType;
use App\Enums\BookingMode;
use App\Enums\Modality;
use App\Enums\QueuePriority;
use App\Enums\UserRole;
use App\Models\Agreement;
use App\Models\Appointment;
use App\Models\AppSetting;
use App\Models\AttendanceEvent;
use App\Models\AuditLog;
use App\Models\B2bInvoice;
use App\Models\B2bInvoiceItem;
use App\Models\Budget;
use App\Models\BudgetItem;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CatalogLink;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ClinicalNote;
use App\Models\LoyaltyDependent;
use App\Models\LoyaltyDue;
use App\Models\LoyaltyPlan;
use App\Models\LoyaltySubscription;
use App\Models\MtAso;
use App\Models\MtAsoEvent;
use App\Models\MtFunction;
use App\Models\MtRisk;
use App\Models\MtSector;
use App\Models\Patient;
use App\Models\PatientAlert;
use App\Models\PaymentMethod;
use App\Models\Payout;
use App\Models\PayoutItem;
use App\Models\PortalAccess;
use App\Models\Procedure;
use App\Models\ProcedurePrice;
use App\Models\Professional;
use App\Models\ProfessionalProcedureCommission;
use App\Models\ProntuarioEvent;
use App\Models\QueueEntry;
use App\Models\Report;
use App\Models\ReportEvent;
use App\Models\ReportTemplate;
use App\Models\RoutineTask;
use App\Models\ServiceOffering;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Popula o banco local de desenvolvimento (sistema_dez) com dados fictícios
 * para permitir testes com substância em todas as páginas. Nunca roda fora do
 * ambiente local. Preserva: usuário marcelo, catálogo importado do STG, o
 * exemplo validado de oferta/escala e os templates de laudo importados.
 *
 * Reexecução: aborta se já semeado. Para recomeçar do zero use REFRESH_MOCK=1
 * (descarta dados operacionais mock e semeia de novo).
 */
class MockDataSeeder extends Seeder
{
    private const MARKER_KEY = 'mock_seed_version';

    private const MARKER_VALUE = '1';

    private const PASSWORD = 'MockDez#2026';

    private const PORTAL_PASSWORD = 'Portal#2026';

    private CashRegister $todayRegister;

    private int $receiptCounter = 0;

    public function run(): void
    {
        $this->guard();
        mt_srand(20260909);
        $refresh = getenv('REFRESH_MOCK') === '1';
        if ($refresh) {
            $this->wipeOperationalData();
        }
        AppSetting::query()->where('key', self::MARKER_KEY)->delete();

        $context = $this->bootstrapUsers();
        $catalog = $this->pickCatalog();
        $this->seedPatients($catalog);
        $offerings = $this->seedOfferingsAndSchedule($catalog, $context);
        $this->seedAppointments($catalog, $offerings);
        $this->seedQueueToday();
        $this->seedBudgets($catalog);
        $this->seedB2bInvoices($offerings);
        $this->seedPayouts($catalog);
        $this->seedLaudos($catalog);
        $this->seedProntuario($catalog);
        $this->seedLoyalty($catalog);
        $this->seedMt($catalog);
        $this->seedChat();
        $this->seedAttendance();
        $this->seedRoutines();
        $this->seedAuditLogs();

        AppSetting::query()->create(['key' => self::MARKER_KEY, 'value' => self::MARKER_VALUE, 'updated_by' => $context['admin']->id, 'updated_at' => now()]);
        $this->command?->info('MockDataSeeder: dados fictícios populados. Login equipe: mock-admin@dez.local / MockDez#2026');
    }

    private function guard(): void
    {
        $database = (string) config('database.connections.pgsql.database');
        if (! app()->environment('local') || $database !== 'sistema_dez') {
            throw new \RuntimeException('MockDataSeeder permitido apenas no banco local sistema_dez em ambiente local.');
        }
        $seeded = AppSetting::query()->where('key', self::MARKER_KEY)->exists();
        if ($seeded && getenv('REFRESH_MOCK') !== '1') {
            throw new \RuntimeException('Dados mock já populados. Use REFRESH_MOCK=1 para recomeçar (descarta e refaz os dados operacionais).');
        }
    }

    private function wipeOperationalData(): void
    {
        DB::statement(<<<'SQL'
            TRUNCATE TABLE
                patients, appointments, queue_entries,
                cash_registers, cash_movements,
                budgets, budget_items,
                b2b_invoices, b2b_invoice_items,
                chat_conversations, chat_messages, chat_participants,
                attendance_events,
                payouts, payout_items,
                reports, report_events,
                clinical_notes, patient_alerts, prontuario_events, portal_accesses, ai_requests,
                loyalty_plans, loyalty_subscriptions, loyalty_dependents, loyalty_dues,
                mt_risks, mt_sectors, mt_functions, mt_asos, mt_aso_events,
                routine_tasks, professional_procedure_commissions
            CASCADE
        SQL);
        $this->command?->warn('REFRESH_MOCK=1: dados operacionais anteriores descartados (catálogo e exemplo validado preservados).');
    }

    /** @return array<string, User> */
    private function bootstrapUsers(): array
    {
        $users = [];
        $spec = [
            'mock-admin@dez.local' => ['Admin Mock', UserRole::Admin],
            'mock-supervisor@dez.local' => ['Supervisor Mock', UserRole::Supervisor],
            'mock-recepcao@dez.local' => ['Recepção Mock', UserRole::Reception],
            'mock-caixa@dez.local' => ['Caixa Mock', UserRole::Cashier],
            'mock-gerente@dez.local' => ['Gerente Mock', UserRole::Manager],
            'mock-medico1@dez.local' => ['Dra. Helena Duarte (mock)', UserRole::Doctor],
            'mock-medico2@dez.local' => ['Dr. Otávio Lacerda (mock)', UserRole::Doctor],
            'mock-medico3@dez.local' => ['Dr. Caio Benevides (mock)', UserRole::Doctor],
        ];
        foreach ($spec as $email => [$name, $role]) {
            $users[$email] = User::query()->updateOrCreate(['email' => $email], [
                'name' => $name, 'role' => $role, 'active' => true, 'password' => Hash::make(self::PASSWORD),
            ]);
        }

        return [
            'admin' => $users['mock-admin@dez.local'],
            'supervisor' => $users['mock-supervisor@dez.local'],
            'reception' => $users['mock-recepcao@dez.local'],
            'cashier' => $users['mock-caixa@dez.local'],
            'manager' => $users['mock-gerente@dez.local'],
            'doctors' => [$users['mock-medico1@dez.local'], $users['mock-medico2@dez.local'], $users['mock-medico3@dez.local']],
        ];
    }

    /**
     * Seleciona convênio/unidade/preços/profissionais do catálogo real importado.
     *
     * @return array<string, mixed>
     */
    private function pickCatalog(): array
    {
        $agreement = Agreement::query()->where('active', true)
            ->whereIn('id', ProcedurePrice::query()->select('agreement_id'))
            ->orderByDesc('is_default')->orderBy('name')->first();
        if (! $agreement) {
            throw new \RuntimeException('Catálogo sem convênios com preços: importe o STG antes de semear.');
        }
        $unit = Unit::query()->where('operational_enabled', true)->where('active', true)
            ->whereIn('id', ProcedurePrice::query()->where('agreement_id', $agreement->id)->select('unit_id'))->first()
            ?? Unit::query()->whereIn('id', ProcedurePrice::query()->where('agreement_id', $agreement->id)->select('unit_id'))->first();
        $unit->forceFill(['operational_enabled' => true])->save();
        $agreement->forceFill(['requires_authorization' => false, 'online_enabled' => true])->save();

        $prices = ProcedurePrice::query()->where('agreement_id', $agreement->id)->where('unit_id', $unit->id)
            ->where('amount', '>', 0)->where('status', 'VALID')
            ->whereIn('procedure_id', Procedure::query()->where('active', true)->select('id'))
            ->with('procedure')->orderBy('amount')->orderBy('id')->get()->unique('procedure_id')->take(6)->values();
        if ($prices->count() < 4) {
            $prices = ProcedurePrice::query()->where('agreement_id', $agreement->id)->where('unit_id', $unit->id)
                ->whereIn('procedure_id', Procedure::query()->where('active', true)->select('id'))
                ->with('procedure')->orderBy('amount')->orderBy('id')->get()->unique('procedure_id')->take(6)->values();
        }
        if ($prices->count() < 3) {
            throw new \RuntimeException('Catálogo sem preços suficientes para montar ofertas mock.');
        }

        $professionals = Professional::query()->where('active', true)->orderBy('name')->orderBy('id')->get()->values();
        if ($professionals->count() < 3) {
            throw new \RuntimeException('Catálogo sem profissionais suficientes.');
        }

        return ['agreement' => $agreement, 'unit' => $unit, 'prices' => $prices, 'professionals' => $professionals];
    }

    /**
     * Pacientes fictícios com CPF válido (hash + pepper), consentimento MOCK.
     */
    private function seedPatients(array $catalog): void
    {
        $first = ['Ana', 'Bruno', 'Carla', 'Diego', 'Elaine', 'Fábio', 'Gabriela', 'Hugo', 'Isabela', 'João', 'Karina', 'Lucas', 'Mariana', 'Nelson', 'Olívia', 'Paulo', 'Renata', 'Sérgio', 'Tatiane', 'Vitor'];
        $last = ['Albuquerque', 'Barbosa', 'Cardoso', 'Dias', 'Estevam', 'Ferreira', 'Gomes', 'Herculano', 'Ivo', 'Juvenal', 'Lima', 'Machado', 'Nogueira', 'Oliveira', 'Pereira', 'Quintela', 'Ribeiro', 'Sampaio', 'Teixeira', 'Vasconcelos'];
        $pepper = (string) config('sistema_dez.identity_pepper');
        for ($i = 0; $i < 80; $i++) {
            $name = $first[mt_rand(0, count($first) - 1)].' '.$last[mt_rand(0, count($last) - 1)].' '.$last[mt_rand(0, count($last) - 1)];
            $cpf = $this->makeCpf(100000001 + $i);
            $hash = hash_hmac('sha256', $cpf, $pepper);
            Patient::query()->updateOrCreate(['cpf_hash' => $hash], [
                'name' => $name,
                'cpf_last_four' => substr($cpf, -4),
                'birth_date' => CarbonImmutable::today()->subDays(mt_rand(365 * 3, 365 * 88))->toDateString(),
                'phone' => '859'.mt_rand(8100, 9999).mt_rand(1000, 9999),
                'email' => 'paciente'.($i + 1).'.mock@dez.local',
                'privacy_consent_at' => now(),
                'privacy_consent_source' => 'MOCK',
            ]);
        }
    }

    /** CPF de 11 dígitos com dígitos verificadores válidos (fictício). */
    private function makeCpf(int $base): string
    {
        $digits = str_pad((string) $base, 9, '0', STR_PAD_LEFT);
        $dv1 = 0;
        for ($i = 0; $i < 9; $i++) {
            $dv1 += (int) $digits[$i] * (10 - $i);
        }
        $dv1 = ($dv1 * 10) % 11 % 10;
        $dv2 = 0;
        $withDv = $digits.$dv1;
        for ($i = 0; $i < 10; $i++) {
            $dv2 += (int) $withDv[$i] * (11 - $i);
        }
        $dv2 = ($dv2 * 10) % 11 % 10;

        return $digits.$dv1.$dv2;
    }

    /**
     * Cria ofertas clínicas sobre o catálogo real, escalas semanais e ocorrências.
     *
     * @return array<string, ServiceOffering>
     */
    private function seedOfferingsAndSchedule(array $catalog, array $users): array
    {
        /** @var Unit $unit */
        $unit = $catalog['unit'];
        /** @var Agreement $agreement */
        $agreement = $catalog['agreement'];
        /** @var Collection<int, ProcedurePrice> $prices */
        $prices = $catalog['prices'];
        /** @var Collection<int, Professional> $professionals */
        $professionals = $catalog['professionals'];

        $linkDoctorUsers = [];
        foreach ([0, 1, 2] as $i) {
            $doctor = $users['doctors'][$i];
            $professional = $professionals->first(fn (Professional $p) => $p->user_id === $doctor->id)
                ?? $professionals->first(fn (Professional $p) => $p->user_id === null);
            if (! $professional) {
                throw new \RuntimeException('Catálogo sem profissionais livres para vincular aos usuários médicos mock.');
            }
            $professional->forceFill(['user_id' => $doctor->id])->save();
            $linkDoctorUsers[$professional->id] = $doctor;
        }

        $offerings = [];
        $plans = [
            ['mode' => BookingMode::FixedSlot, 'days' => [2, 4], 'start' => '08:00', 'end' => '11:00'],
            ['mode' => BookingMode::FixedSlot, 'days' => [3, 5], 'start' => '14:00', 'end' => '17:00'],
            ['mode' => BookingMode::FixedSlot, 'days' => [1, 6], 'start' => '08:00', 'end' => '11:00'],
            ['mode' => BookingMode::FixedSlot, 'days' => [1, 2, 3, 4, 5], 'start' => '09:00', 'end' => '11:30'],
            ['mode' => BookingMode::ArrivalBlock, 'days' => [1, 2, 3, 4, 5], 'start' => '08:00', 'end' => '12:00'],
            ['mode' => BookingMode::ArrivalBlock, 'days' => [2, 4, 6], 'start' => '14:00', 'end' => '18:00'],
        ];
        foreach ($plans as $index => $plan) {
            /** @var ProcedurePrice $price */
            $price = $prices[$index % $prices->count()];
            /** @var Professional $professional */
            $professional = $professionals[($index + 1) % $professionals->count()];
            $link = CatalogLink::query()->firstOrCreate([
                'unit_id' => $unit->id, 'procedure_id' => $price->procedure_id,
                'professional_id' => $professional->id, 'agreement_id' => $agreement->id,
            ], ['active' => true]);

            /** @var ServiceOffering $offering */
            $offering = ServiceOffering::query()->firstOrCreate([
                'unit_id' => $unit->id, 'procedure_id' => $price->procedure_id,
                'professional_id' => $professional->id, 'agreement_id' => $agreement->id,
                'booking_mode' => $plan['mode'],
            ], [
                'modality' => Modality::Presential, 'price' => $price->amount,
                'duration_override_minutes' => $price->procedure->duration_minutes,
                'arrival_block_minutes' => 60, 'arrival_block_capacity' => 3,
                'online_enabled' => true, 'active' => true,
            ]);
            $offering->forceFill([
                'catalog_link_id' => $link->id, 'procedure_price_id' => $price->id,
                'published_at' => now()->subDays(30), 'published_until' => now()->addDays(60), 'online_paused' => false,
            ])->save();

            foreach ($plan['days'] as $weekday) {
                $exists = $offering->scheduleWindows()->where('weekday', $weekday)->where('starts_at', $plan['start'].':00')->exists();
                if (! $exists) {
                    $offering->scheduleWindows()->create([
                        'effective_from' => CarbonImmutable::today()->subDays(60)->toDateString(),
                        'weekday' => $weekday, 'starts_at' => $plan['start'], 'ends_at' => $plan['end'],
                        'slot_minutes' => $plan['mode'] === BookingMode::FixedSlot ? null : null,
                        'active' => true,
                    ]);
                }
            }
            app(MaterializeScheduleOccurrences::class)->handle($offering, CarbonImmutable::today()->subDays(45), CarbonImmutable::today()->addDays(21));
            $offerings[] = $offering;
        }

        $b2bAgreement = Agreement::query()->firstOrCreate(['name' => 'TRANSPORTADORA SAO LUIZ MOCK LTDA'], [
            'type' => 'B2B', 'is_default' => false, 'requires_authorization' => false, 'online_enabled' => false, 'active' => true,
        ]);
        ProcedurePrice::query()->firstOrCreate([
            'procedure_id' => $prices[0]->procedure_id, 'agreement_id' => $b2bAgreement->id, 'unit_id' => $unit->id,
        ], $this->mockPriceExtra($prices[0], $b2bAgreement->id, $unit->id));
        $b2bLink = CatalogLink::query()->firstOrCreate([
            'unit_id' => $unit->id, 'procedure_id' => $prices[0]->procedure_id,
            'professional_id' => $professionals[0]->id, 'agreement_id' => $b2bAgreement->id,
        ], ['active' => true]);
        $b2bOffering = ServiceOffering::query()->firstOrCreate([
            'unit_id' => $unit->id, 'procedure_id' => $prices[0]->procedure_id,
            'professional_id' => $professionals[0]->id, 'agreement_id' => $b2bAgreement->id,
            'booking_mode' => BookingMode::FixedSlot,
        ], [
            'modality' => Modality::Presential, 'price' => $prices[0]->amount,
            'duration_override_minutes' => $prices[0]->procedure->duration_minutes,
            'arrival_block_minutes' => 60, 'arrival_block_capacity' => 3,
            'online_enabled' => false, 'active' => true,
        ]);
        $b2bOffering->forceFill(['catalog_link_id' => $b2bLink->id])->save();
        foreach ([1, 2, 3, 4, 5] as $weekday) {
            $exists = $b2bOffering->scheduleWindows()->where('weekday', $weekday)->where('starts_at', '07:00')->exists();
            if (! $exists) {
                $b2bOffering->scheduleWindows()->create([
                    'effective_from' => CarbonImmutable::today()->subDays(70)->toDateString(),
                    'weekday' => $weekday, 'starts_at' => '07:00', 'ends_at' => '11:00', 'active' => true,
                ]);
            }
        }
        app(MaterializeScheduleOccurrences::class)->handle($b2bOffering, CarbonImmutable::today()->subDays(60), CarbonImmutable::today());

        return ['main' => $offerings, 'b2b' => $b2bOffering, 'b2bAgreement' => $b2bAgreement, 'doctorUsers' => $linkDoctorUsers];
    }

    private function seedAppointments(array $catalog, array $offerings): void
    {
        $patients = Patient::query()->orderBy('name')->get();
        $methods = PaymentMethod::query()->where('active', true)->get();

        $pastByDate = [];
        $this->bookRange($offerings['main'], $patients, $this->today()->subDays(45), $this->today()->subDay(), 42, function (ServiceOffering $offering, Patient $patient, CarbonImmutable $when) use (&$pastByDate): void {
            [$status, $payment, $kind, $origin] = $this->pickPastState();
            $this->createAppointment($offering, $patient, $when, $status, $payment, $kind, $origin, null, $pastByDate);
        });

        $b2bPatients = $patients->take(20);
        $b2bByDate = [];
        $b2bCodeSeq = 0;
        $this->bookRange([$offerings['b2b']], $b2bPatients, $this->today()->subDays(60), $this->today()->subDay(), 12, function (ServiceOffering $offering, Patient $patient, CarbonImmutable $when) use (&$b2bByDate, &$b2bCodeSeq): void {
            $code = 'B2B-'.str_pad((string) (++$b2bCodeSeq), 4, '0', STR_PAD_LEFT);
            $this->createAppointment($offering, $patient, $when, mt_rand(1, 10) === 1 ? 'CANCELLED' : 'DONE', 'AUTHORIZED', 'NEW', 'INTERNAL', $code, $b2bByDate);
        });

        $todayByDate = [];
        $this->bookRange($offerings['main'], $patients, $this->today(), $this->today(), 80, function (ServiceOffering $offering, Patient $patient, CarbonImmutable $when) use (&$todayByDate): void {
            $morning = $when->hour < 12;
            $status = $morning ? (mt_rand(1, 10) <= 4 ? 'DONE' : 'CHECKED_IN') : 'CONFIRMED';
            $payment = $status === 'DONE' ? (mt_rand(1, 10) === 1 ? 'PENDING' : 'PAID') : 'PENDING';
            $this->createAppointment($offering, $patient, $when, $status, $payment, 'NEW', mt_rand(0, 1) ? 'ONLINE' : 'INTERNAL', null, $todayByDate);
        });

        $this->bookRange($offerings['main'], $patients, $this->today()->addDay(), $this->today()->addDays(21), 45, function (ServiceOffering $offering, Patient $patient, CarbonImmutable $when): void {
            $status = mt_rand(1, 20) === 1 ? 'CANCELLED' : 'CONFIRMED';
            $byDate = [];
            $this->createAppointment($offering, $patient, $when, $status, 'PENDING', mt_rand(1, 8) === 1 ? 'RETURN' : 'NEW', mt_rand(0, 1) ? 'ONLINE' : 'INTERNAL', null, $byDate);
        });

        $this->seedCashRegisters($catalog['unit'], $todayByDate, $pastByDate, $methods);
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::today((string) config('sistema_dez.timezone'));
    }

    /** Campos obrigatórios (nature/source_*) para preços criados fora do import STG. */
    private function mockPriceExtra(ProcedurePrice $reference, string $agreementId, string $unitId): array
    {
        $key = 'MOCK-'.md5($reference->procedure_id.'|'.$agreementId.'|'.$unitId);

        return [
            'amount' => $reference->amount, 'nature' => $reference->nature, 'status' => 'VALID',
            'valid_from' => now()->subDays(60)->toDateString(),
            'source_file' => 'MOCK-SEEDER', 'source_key' => $key, 'source_checksum' => $key,
        ];
    }

    private function pickPastState(): array
    {
        $roll = mt_rand(1, 100);
        if ($roll <= 72) {
            return ['DONE', mt_rand(1, 100) <= 85 ? 'PAID' : 'PENDING', 'NEW', mt_rand(0, 1) ? 'ONLINE' : 'INTERNAL'];
        }
        if ($roll <= 82) {
            return ['NO_SHOW', 'PENDING', 'NEW', 'INTERNAL'];
        }
        if ($roll <= 94) {
            return ['CANCELLED', 'PENDING', 'NEW', mt_rand(0, 1) ? 'ONLINE' : 'INTERNAL'];
        }

        return ['CONFIRMED', 'PENDING', 'NEW', 'INTERNAL'];
    }

    /**
     * Agenda consultas ocupando ocorrências reais das ofertas.
     *
     * @param  array<int, ServiceOffering>  $offerings
     * @param  \Closure(ServiceOffering, Patient, CarbonImmutable): void  $make
     */
    private function bookRange(array $offerings, $patients, CarbonImmutable $from, CarbonImmutable $to, int $bookPercent, \Closure $make): void
    {
        $patientList = $patients instanceof Collection ? $patients->values() : collect($patients);
        foreach ($offerings as $offering) {
            $occurrences = $offering->scheduleOccurrences()->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->orderBy('date')->orderBy('starts_at')->get();
            foreach ($occurrences as $index => $occurrence) {
                $capacity = $offering->booking_mode === BookingMode::ArrivalBlock ? $offering->arrival_block_capacity : 1;
                for ($slot = 0; $slot < $capacity; $slot++) {
                    if (mt_rand(1, 100) > $bookPercent) {
                        continue;
                    }
                    $patient = $patientList[mt_rand(0, $patientList->count() - 1)];
                    $when = CarbonImmutable::parse($occurrence->date->toDateString().' '.$occurrence->starts_at, (string) config('sistema_dez.timezone'));
                    $make($offering, $patient, $when);
                }
            }
        }
    }

    /**
     * @param  array<string, array<int, Appointment>>  $byDate
     */
    private function createAppointment(ServiceOffering $offering, Patient $patient, CarbonImmutable $when, string $status, string $payment, string $kind, string $origin, ?string $b2bCode, array &$byDate): Appointment
    {
        $done = $status === 'DONE' || $status === 'CHECKED_IN';
        /** @var Appointment $appointment */
        $appointment = Appointment::query()->create([
            'patient_id' => $patient->id,
            'service_offering_id' => $offering->id,
            'scheduled_at' => $when,
            'recommended_arrival_at' => $when->subMinutes(15),
            'status' => $status,
            'kind' => $kind,
            'origin' => $origin,
            'price_snapshot' => $offering->price,
            'payment_status' => $payment,
            'b2b_authorization_code' => $b2bCode,
            'care_started_at' => $done ? $when->addMinutes(5) : null,
            'idempotency_key' => (string) Str::uuid(),
            'created_by' => $origin === 'INTERNAL' ? User::query()->where('email', 'mock-admin@dez.local')->value('id') : null,
        ]);
        $appointment->setRelation('patient', $patient);
        $appointment->setRelation('serviceOffering', $offering);
        if ($payment === 'PAID') {
            $byDate[$when->toDateString()][] = $appointment;
        }

        return $appointment;
    }

    /**
     * Caixas diários (FECHADO) com RECEBIMENTO/SANGRIA, caixa de hoje ABERTO.
     *
     * @param  array<string, array<int, Appointment>>  $todayByDate
     * @param  array<string, array<int, Appointment>>  $pastByDate
     */
    private function seedCashRegisters(Unit $unit, array $todayByDate, array $pastByDate, $methods): void
    {
        $cashier = User::query()->where('email', 'mock-caixa@dez.local')->firstOrFail();
        $cashMethod = $methods->firstWhere('code', 'ESPECIE') ?? $methods->first();
        $todayKey = $this->today()->toDateString();

        krsort($pastByDate);
        $dayIndex = 0;
        foreach ($pastByDate as $date => $appointments) {
            if ($date >= $todayKey || $dayIndex >= 22) {
                foreach ($appointments as $appointment) {
                    $appointment->forceFill(['payment_status' => 'PENDING', 'paid_at' => null])->save();
                }

                continue;
            }
            $dayIndex++;
            $opened = CarbonImmutable::parse($date.' 07:30:00', (string) config('sistema_dez.timezone'));
            /** @var CashRegister $register */
            $register = CashRegister::query()->create([
                'unit_id' => $unit->id, 'shift' => 'UNICO', 'float_amount' => '200.00', 'status' => 'FECHADO',
                'opened_by' => $cashier->id, 'closed_by' => $cashier->id, 'opened_at' => $opened, 'closed_at' => $opened->setTime(18, 5),
                'last_receipt_number' => 0, 'counted_cash' => null, 'cash_difference' => null,
            ]);
            $receipt = 0;
            $cashIn = '0.00';
            $sangriaOut = '0.00';
            foreach ($appointments as $appointment) {
                /** @var PaymentMethod $method */
                $method = $methods[mt_rand(0, $methods->count() - 1)];
                $receipt++;
                $movement = $this->createMovement($register, 'RECEBIMENTO', $method, $appointment->price_snapshot, $receipt, 'Consulta - '.($appointment->patient?->name ?? ''), $cashier, $appointment->id);
                if ($method->code === 'ESPECIE') {
                    $cashIn = bcadd($cashIn, $movement->net_amount, 2);
                }
                $appointment->forceFill([
                    'paid_at' => $appointment->scheduled_at->addMinutes(30),
                    'payment_method_id' => $method->id, 'cash_movement_id' => $movement->id,
                ])->save();
            }
            if (mt_rand(1, 3) === 1) {
                $receipt++;
                $sangria = $this->createMovement($register, 'SANGRIA', $cashMethod, '300.00', $receipt, 'Sangria mock do dia', $cashier, null);
                $sangriaOut = bcadd($sangriaOut, $sangria->net_amount, 2);
            }
            $register->forceFill(['counted_cash' => bcsub(bcadd('200.00', $cashIn, 2), $sangriaOut, 2), 'cash_difference' => '0.00'])->save();
        }

        /** @var CashRegister $todayRegister */
        $todayRegister = CashRegister::query()->create([
            'unit_id' => $unit->id, 'shift' => 'UNICO', 'float_amount' => '200.00', 'status' => 'ABERTO',
            'opened_by' => $cashier->id, 'opened_at' => $this->today()->setTime(7, 30),
            'last_receipt_number' => 0,
        ]);
        $this->todayRegister = $todayRegister;
        $receipt = 0;
        foreach ($todayByDate[$todayKey] ?? [] as $appointment) {
            /** @var PaymentMethod $method */
            $method = $methods[mt_rand(0, $methods->count() - 1)];
            $receipt++;
            $movement = $this->createMovement($todayRegister, 'RECEBIMENTO', $method, $appointment->price_snapshot, $receipt, 'Consulta - '.($appointment->patient?->name ?? ''), $cashier, $appointment->id);
            $appointment->forceFill([
                'paid_at' => $appointment->scheduled_at->addMinutes(30),
                'payment_method_id' => $method->id, 'cash_movement_id' => $movement->id,
            ])->save();
        }
        $this->receiptCounter = $receipt;
    }

    private function createMovement(CashRegister $register, string $type, PaymentMethod $method, string $gross, int $receipt, ?string $description, User $by, ?string $appointmentId): CashMovement
    {
        $fee = bcmul($gross, (string) ($method->extra_percent ?? 0), 2);
        $register->forceFill(['last_receipt_number' => $receipt])->save();

        return CashMovement::query()->create([
            'cash_register_id' => $register->id, 'type' => $type, 'payment_method_id' => $method->id,
            'gross_amount' => $gross, 'fee_amount' => $fee, 'net_amount' => bcsub($gross, $fee, 2),
            'appointment_id' => $appointmentId, 'receipt_number' => $receipt,
            'description' => $description, 'created_by' => $by->id,
        ]);
    }

    private function nextReceipt(): int
    {
        return ++$this->receiptCounter;
    }

    private function seedQueueToday(): void
    {
        $today = CarbonImmutable::today()->toDateString();
        $reception = User::query()->where('email', 'mock-recepcao@dez.local')->firstOrFail();
        $appointments = Appointment::query()->whereDate('scheduled_at', $today)
            ->whereIn('status', ['CONFIRMED', 'CHECKED_IN'])->with('serviceOffering:id,unit_id')->orderBy('scheduled_at')->get();
        $sequence = 1;
        $priorities = [QueuePriority::Pcd, QueuePriority::Gestante, QueuePriority::Idoso80, QueuePriority::Idoso60, null, null, null, null];
        foreach ($appointments as $index => $appointment) {
            $priority = $priorities[$index % count($priorities)];
            $checkedIn = $appointment->scheduled_at->subMinutes(20);
            $called = $index < 2;
            QueueEntry::query()->create([
                'appointment_id' => $appointment->id, 'unit_id' => $appointment->serviceOffering->unit_id,
                'service_offering_id' => $appointment->service_offering_id, 'service_date' => $today,
                'checked_in_at' => $checkedIn, 'original_sequence' => $sequence++,
                'priority_code' => $priority?->value, 'priority_rank' => $priority?->rank() ?? 100,
                'priority_reason' => $priority?->label(),
                'status' => $called ? 'CALLED' : 'WAITING',
                'called_at' => $called ? now()->subMinutes(30 - $index) : null,
                'called_by' => $called ? $reception->id : null,
            ]);
            if ($appointment->status === 'CONFIRMED' && $index < 6) {
                $appointment->forceFill(['status' => 'CHECKED_IN'])->save();
            }
        }
    }

    private function seedBudgets(array $catalog): void
    {
        $admin = User::query()->where('email', 'mock-admin@dez.local')->firstOrFail();
        /** @var Unit $unit */
        $unit = $catalog['unit'];
        $patients = Patient::query()->orderBy('name')->get();
        $prices = $catalog['prices'];
        for ($i = 0; $i < 25; $i++) {
            /** @var Patient $patient */
            $patient = $patients[mt_rand(0, $patients->count() - 1)];
            $roll = mt_rand(1, 100);
            $status = $roll <= 35 ? 'RASCUNHO' : ($roll <= 60 ? 'ENVIADO' : ($roll <= 80 ? 'ACEITO' : ($roll <= 92 ? 'RECUSADO' : 'PERDIDO')));
            /** @var Budget $budget */
            $budget = Budget::query()->create([
                'patient_id' => $patient->id, 'unit_id' => $unit->id, 'agreement_id' => $catalog['agreement']->id,
                'status' => $status, 'subtotal_gross' => '0.00',
                'sent_at' => in_array($status, ['ENVIADO', 'ACEITO', 'RECUSADO', 'PERDIDO']) ? now()->subDays(mt_rand(2, 25)) : null,
                'resolved_at' => in_array($status, ['ACEITO', 'RECUSADO', 'PERDIDO']) ? now()->subDays(mt_rand(1, 10)) : null,
                'resolution_note' => $status === 'RECUSADO' ? 'Paciente optou por parcelamento no cartão.' : null,
                'created_by' => $admin->id,
            ]);
            $total = '0.00';
            foreach (mt_rand(1, 3) === 1 ? [0] : [0, 1, 2] as $itemIndex) {
                /** @var ProcedurePrice $price */
                $price = $prices[($i + $itemIndex) % $prices->count()];
                BudgetItem::query()->create([
                    'budget_id' => $budget->id, 'procedure_id' => $price->procedure_id, 'procedure_price_id' => $price->id,
                    'procedure_name' => $price->procedure->name, 'amount' => $price->amount, 'quantity' => 1,
                ]);
                $total = bcadd($total, $price->amount, 2);
            }
            $budget->forceFill(['subtotal_gross' => $total])->save();
        }
    }

    private function seedB2bInvoices(array $offerings): void
    {
        $admin = User::query()->where('email', 'mock-admin@dez.local')->firstOrFail();
        /** @var Agreement $b2bAgreement */
        $b2bAgreement = $offerings['b2bAgreement'];
        $appointments = Appointment::query()->where('payment_status', 'AUTHORIZED')
            ->whereHas('serviceOffering', fn ($q) => $q->where('agreement_id', $b2bAgreement->id))
            ->with(['serviceOffering.procedure:id,name', 'patient:id,name'])->orderBy('scheduled_at')->get();
        $byMonth = $appointments->groupBy(fn (Appointment $a) => $a->scheduled_at->format('Y-m'));
        foreach ($byMonth as $month => $monthAppointments) {
            $competence = CarbonImmutable::parse($month.'-01');
            $gross = $monthAppointments->sum('price_snapshot');
            $paid = $competence->lessThan(CarbonImmutable::today()->startOfMonth());
            /** @var B2bInvoice $invoice */
            $invoice = B2bInvoice::query()->create([
                'agreement_id' => $b2bAgreement->id, 'competence' => $competence->toDateString(),
                'status' => $paid ? 'PAGA' : (mt_rand(0, 1) ? 'ENVIADA' : 'EMITIDA'),
                'subtotal_gross' => $gross, 'settled_amount' => $paid ? $gross : '0.00',
                'issued_at' => $competence->endOfMonth()->setTime(10, 0),
                'due_at' => $competence->addMonths(1)->setDay(10)->toDateString(),
                'sent_at' => $competence->endOfMonth()->setTime(11, 0),
                'settled_at' => $paid ? $competence->addMonths(1)->setDay(8)->setTime(15, 0) : null,
                'proof_reference' => $paid ? 'COMPROVANTE-MOCK-'.$competence->format('Ym') : null,
                'created_by' => $admin->id,
            ]);
            foreach ($monthAppointments as $appointment) {
                B2bInvoiceItem::query()->create([
                    'b2b_invoice_id' => $invoice->id, 'appointment_id' => $appointment->id,
                    'scheduled_at' => $appointment->scheduled_at, 'patient_name' => $appointment->patient?->name ?? 'Funcionário mock',
                    'procedure_name' => $appointment->serviceOffering->procedure->name, 'gross_amount' => $appointment->price_snapshot,
                ]);
            }
        }
    }

    private function seedPayouts(array $catalog): void
    {
        $admin = User::query()->where('email', 'mock-admin@dez.local')->firstOrFail();
        $pix = PaymentMethod::query()->where('code', 'PIX')->first();
        $professionals = $catalog['professionals']->take(3);
        $today = CarbonImmutable::today();
        foreach ($professionals as $index => $professional) {
            $percent = 40.0;
            $procedureIds = collect();
            foreach ([$today->subMonth()->startOfMonth(), $today->startOfMonth()] as $i => $period) {
                $periodTo = $period->endOfMonth();
                $appointments = Appointment::query()
                    ->whereHas('serviceOffering', fn ($q) => $q->where('professional_id', $professional->id))
                    ->whereBetween('scheduled_at', [$period->startOfDay(), $periodTo->endOfDay()])
                    ->with('serviceOffering:id,professional_id,procedure_id')
                    ->where('status', 'DONE')->where('payment_status', 'PAID')->get();
                $procedureIds = $procedureIds->merge($appointments->pluck('serviceOffering.procedure_id'));
                if ($appointments->isEmpty()) {
                    continue;
                }
                $subtotal = $appointments->sum('price_snapshot');
                $commission = round($subtotal * $percent / 100, 2);
                $closed = $i === 0;
                /** @var Payout $payout */
                $payout = Payout::query()->create([
                    'professional_id' => $professional->id, 'period_from' => $period->toDateString(),
                    'period_to' => $periodTo->toDateString(), 'status' => $closed ? 'PAID' : 'OPEN',
                    'subtotal_gross' => $subtotal, 'total_commission' => $commission,
                    'confirmed_by' => $closed ? $admin->id : null, 'confirmed_at' => $closed ? $periodTo->addDays(2) : null,
                    'paid_by' => $closed ? $admin->id : null, 'paid_at' => $closed ? $periodTo->addDays(5) : null,
                    'payment_method_id' => $closed ? $pix?->id : null,
                    'proof_reference' => $closed ? 'REP-MOCK-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT) : null,
                ]);
                foreach ($appointments as $appointment) {
                    PayoutItem::query()->create([
                        'payout_id' => $payout->id, 'appointment_id' => $appointment->id,
                        'gross_amount' => $appointment->price_snapshot,
                        'commission_amount' => round($appointment->price_snapshot * $percent / 100, 2),
                        'rule' => 'PERCENT '.number_format($percent, 2).'%',
                    ]);
                }
            }
            foreach ($procedureIds->unique()->filter() as $procedureId) {
                ProfessionalProcedureCommission::query()->firstOrCreate([
                    'professional_id' => $professional->id, 'procedure_id' => $procedureId,
                ], ['commission_type' => 'PERCENT', 'commission_value' => '40.00']);
            }
        }
    }

    private function seedLaudos(array $catalog): void
    {
        $admin = User::query()->where('email', 'mock-admin@dez.local')->firstOrFail();
        $doctorUsers = User::query()->whereIn('email', ['mock-medico1@dez.local', 'mock-medico2@dez.local', 'mock-medico3@dez.local'])->get();
        $appointments = Appointment::query()->where('status', 'DONE')
            ->where('scheduled_at', '<', now()->subDay())
            ->with(['serviceOffering.procedure:id,name', 'serviceOffering.professional:id'])
            ->orderBy('scheduled_at')->get()->take(35);
        $printed = 0;
        $published = 0;
        foreach ($appointments as $index => $appointment) {
            /** @var Model $report */
            $template = ReportTemplate::query()->where('active', true)
                ->where(fn ($q) => $q->whereNull('procedure_id')->orWhere('procedure_id', $appointment->serviceOffering->procedure_id))
                ->inRandomOrder()->first();
            $finalized = mt_rand(1, 10) <= 8;
            $typedBy = $doctorUsers[$index % $doctorUsers->count()];
            $printCount = $finalized && mt_rand(1, 10) <= 5 ? mt_rand(1, 2) : 0;
            /** @var Report $report */
            $report = Report::query()->create([
                'appointment_id' => $appointment->id, 'patient_id' => $appointment->patient_id,
                'procedure_id' => $appointment->serviceOffering->procedure_id,
                'doctor_id' => $appointment->serviceOffering->professional_id, 'template_id' => $template?->id,
                'content_html' => '<h2>Laudo '.($appointment->serviceOffering->procedure->name).'</h2><p>'
                    .$this->clinicalText().'</p><p>Conclusão: '.$this->clinicalConclusion().'</p>',
                'status' => $finalized ? Report::STATUS_FINALIZADO : Report::STATUS_RASCUNHO,
                'typed_by' => $typedBy->id,
                'finalized_at' => $finalized ? $appointment->scheduled_at->addHours(2) : null,
                'retention_until' => CarbonImmutable::today()->addYears(20)->toDateString(),
                'print_count' => $printCount,
                'last_print_by' => $printCount > 0 ? $admin->id : null,
                'last_printed_at' => $printCount > 0 ? $appointment->scheduled_at->addHours(3) : null,
            ]);
            ReportEvent::query()->create(['report_id' => $report->id, 'event' => ReportEvent::CREATED, 'by_user_id' => $typedBy->id]);
            if ($finalized) {
                ReportEvent::query()->create(['report_id' => $report->id, 'event' => ReportEvent::FINALIZED, 'by_user_id' => $typedBy->id]);
            }
            if ($printCount > 0) {
                ReportEvent::query()->create(['report_id' => $report->id, 'event' => $printCount > 1 ? ReportEvent::REPRINTED : ReportEvent::PRINTED, 'by_user_id' => $admin->id]);
            }
            if ($finalized && $published < 3 && mt_rand(1, 4) === 1) {
                $published++;
                /** @var PortalAccess $access */
                $access = PortalAccess::query()->create([
                    'patient_id' => $report->patient_id, 'login' => 'MOCK'.str_pad((string) ($published + 1000), 6, '0', STR_PAD_LEFT),
                    'password_hash' => Hash::make(self::PORTAL_PASSWORD),
                    'token_hash' => hash('sha256', 'mock-portal-token-'.$published),
                    'expires_at' => now()->addDays(7), 'access_count' => mt_rand(1, 4),
                    'last_accessed_at' => now()->subDays($published), 'created_by' => $admin->id,
                ]);
                $report->forceFill([
                    'portal_access_id' => $access->id, 'portal_published_at' => now()->subDays($published),
                    'portal_published_by' => $admin->id,
                ])->save();
                ProntuarioEvent::query()->create(['patient_id' => $report->patient_id, 'appointment_id' => $appointment->id, 'event' => ProntuarioEvent::PUBLISHED, 'by_user_id' => $admin->id, 'payload' => ['report_id' => $report->id]]);
            }
        }
    }

    private function clinicalText(): string
    {
        return 'Exame realizado conforme técnica padrão. '.fake()->randomElement([
            'Órgãos e estruturas estudadas sem alterações significativas.',
            'Pequena alteração inicial sem relevância clínica atual.',
            'Achados compatíveis com processo inflamatório em resolução.',
            'Estudo dinâmico sem alterações. Padrão de distribuição preservado.',
        ]).' Conduta: '.fake()->randomElement(['seguimento ambulatorial', 'reavaliação em 6 meses', 'tratamento clínico instituído', 'exame controle em 30 dias']).'.';
    }

    private function clinicalConclusion(): string
    {
        return fake()->randomElement([
            'Sem evidências de alterações estruturais relevantes.',
            'Sugestivo de quadro benigno, com correlação clínica recomendada.',
            'Alteração discreta, sem necessidade de intervenção imediata.',
            'Dentro dos parâmetros esperados para a faixa etária.',
        ]);
    }

    private function seedProntuario(array $catalog): void
    {
        $doctorUsers = User::query()->whereIn('email', ['mock-medico1@dez.local', 'mock-medico2@dez.local', 'mock-medico3@dez.local'])->get();
        $patients = Patient::query()->orderBy('name')->get();
        $kinds = [ClinicalNote::KIND_EVOLUCAO, ClinicalNote::KIND_ANAMNESE, ClinicalNote::KIND_INTERCORRENCIA, ClinicalNote::KIND_ALTA];
        foreach ($patients as $index => $patient) {
            if ($index % 2 === 0) {
                $note = ClinicalNote::query()->create([
                    'patient_id' => $patient->id, 'professional_id' => $catalog['professionals'][$index % 3]->id,
                    'kind' => $kinds[$index % 4],
                    'content_html' => '<p>'.fake()->randomElement([
                        'Paciente refere melhora dos sintomas após medicação prescrita. Sem queixas novas.',
                        'Queixa de dor lombar há 3 dias, sem irradiação, sem sinais de alarme.',
                        'Seguimento clínico: PA 128/82, glicemia 110 mg/dL, sem intercorrências.',
                        'Alta clínica: sintomas resolvidos, orientações de manutenção fornecidas.',
                    ]).'</p>',
                    'created_by' => $doctorUsers[$index % 3]->id,
                    'retention_until' => CarbonImmutable::today()->addYears(20)->toDateString(),
                ]);
                ProntuarioEvent::query()->create(['patient_id' => $patient->id, 'event' => ProntuarioEvent::NOTE_CREATED, 'by_user_id' => $note->created_by, 'payload' => ['note_id' => $note->id, 'kind' => $note->kind]]);
            }
            if ($index % 6 === 0) {
                $alert = PatientAlert::query()->create([
                    'patient_id' => $patient->id, 'kind' => mt_rand(0, 1) ? PatientAlert::KIND_ALERGIA : PatientAlert::KIND_CONDICAO,
                    'title' => fake()->randomElement(['Alergia a dipirona', 'Alergia a penicilina', 'Diabetes tipo 2', 'Hipertensão arterial', 'Uso de anticoagulante']),
                    'detail' => fake()->sentence(6), 'active' => true, 'created_by' => $doctorUsers[$index % 3]->id,
                ]);
                ProntuarioEvent::query()->create(['patient_id' => $patient->id, 'event' => ProntuarioEvent::ALERT_ADDED, 'by_user_id' => $alert->created_by, 'payload' => ['alert_id' => $alert->id, 'kind' => $alert->kind]]);
            }
        }
        foreach ($patients->take(20) as $patient) {
            ProntuarioEvent::query()->create(['patient_id' => $patient->id, 'event' => ProntuarioEvent::VIEWED, 'by_user_id' => $doctorUsers[mt_rand(0, 2)]->id]);
        }
    }

    private function seedLoyalty(array $catalog): void
    {
        $admin = User::query()->where('email', 'mock-admin@dez.local')->firstOrFail();
        $cashier = User::query()->where('email', 'mock-caixa@dez.local')->firstOrFail();
        /** @var Unit $unit */
        $unit = $catalog['unit'];
        $planAgreements = Agreement::query()->where('name', 'like', '%SOU DEZ%')->where('active', true)->orderBy('name')->limit(2)->get();
        if ($planAgreements->isEmpty()) {
            $planAgreements = collect([Agreement::query()->create([
                'name' => 'SOU DEZ COMPLETO MOCK', 'type' => 'PRIVATE', 'is_default' => false,
                'requires_authorization' => false, 'online_enabled' => true, 'active' => true,
            ])]);
        }
        $patients = Patient::query()->orderBy('name')->get();
        $subscriptions = [];
        foreach ($planAgreements as $planIndex => $planAgreement) {
            $planAgreement->forceFill(['requires_authorization' => false, 'online_enabled' => true])->save();
            /** @var Collection<int, ProcedurePrice> $prices */
            $prices = $catalog['prices'];
            $price = $prices[$planIndex];
            ProcedurePrice::query()->firstOrCreate([
                'procedure_id' => $price->procedure_id, 'agreement_id' => $planAgreement->id, 'unit_id' => $unit->id,
            ], $this->mockPriceExtra($price, $planAgreement->id, $unit->id));
            /** @var LoyaltyPlan $plan */
            $plan = LoyaltyPlan::query()->firstOrCreate(['agreement_id' => $planAgreement->id], [
                'name' => 'SOU DEZ '.($planIndex === 0 ? 'COMPLETO' : 'ESSENCIAL'), 'monthly_fee' => $planIndex === 0 ? '65.00' : '45.00', 'due_day' => 10, 'active' => true,
            ]);
            for ($i = 0; $i < 9; $i++) {
                /** @var Patient $patient */
                $patient = $patients[$planIndex * 9 + $i];
                $patient->forceFill(['default_agreement_id' => $planAgreement->id])->save();
                $cancelled = mt_rand(1, 12) === 1;
                /** @var LoyaltySubscription $subscription */
                $subscription = LoyaltySubscription::query()->create([
                    'plan_id' => $plan->id, 'patient_id' => $patient->id,
                    'status' => $cancelled ? 'CANCELLED' : 'ACTIVE',
                    'started_at' => CarbonImmutable::today()->subMonths(3)->toDateString(),
                    'ended_at' => $cancelled ? CarbonImmutable::today()->subDays(10)->toDateString() : null,
                    'created_by' => $admin->id,
                ]);
                $this->seedDuesFor($subscription, $plan->monthly_fee, $cancelled, $cashier);
                if ($i >= 6) {
                    LoyaltyDependent::query()->create(['subscription_id' => $subscription->id, 'patient_id' => $patients[72 + $i - 6]->id, 'active' => true]);
                }
                $subscriptions[] = $subscription;
            }
        }
    }

    private function seedDuesFor(LoyaltySubscription $subscription, string $fee, bool $cancelled, User $cashier): void
    {
        $today = CarbonImmutable::today((string) config('sistema_dez.timezone'));
        $competences = [$today->subMonths(3)->startOfMonth(), $today->subMonths(2)->startOfMonth(), $today->subMonth()->startOfMonth(), $today->startOfMonth()];
        foreach ($competences as $index => $competence) {
            $isCurrent = $index === 3;
            $paid = ! $cancelled && (! $isCurrent || mt_rand(1, 100) <= 60);
            /** @var LoyaltyDue $due */
            $due = LoyaltyDue::query()->create([
                'subscription_id' => $subscription->id, 'competence' => $competence->toDateString(),
                'amount' => $fee, 'due_date' => $competence->setDay(10)->toDateString(),
                'status' => $paid ? 'PAGA' : 'PENDENTE',
                'paid_at' => $paid ? $competence->setDay(10)->setTime(mt_rand(9, 17), 0) : null,
                'collected_by' => $paid ? $cashier->id : null,
            ]);
            if ($paid && $isCurrent && $this->todayRegister->status === 'ABERTO') {
                $pix = PaymentMethod::query()->where('code', 'PIX')->first();
                if ($pix) {
                    $movement = $this->createMovement($this->todayRegister, 'MENSALIDADE', $pix, $fee, $this->nextReceipt(), 'Mensalidade Sou DEZ', $cashier, null);
                    $due->forceFill(['cash_movement_id' => $movement->id])->save();
                }
            }
        }
    }

    private function seedMt(array $catalog): void
    {
        $admin = User::query()->where('email', 'mock-admin@dez.local')->firstOrFail();
        $company = Agreement::query()->firstOrCreate(['name' => 'INDUSTRIA METALURGICA MOCK S/A'], [
            'type' => 'B2B', 'is_default' => false, 'requires_authorization' => false, 'online_enabled' => false, 'active' => true,
        ]);
        $risks = [];
        foreach (['RUIDO', 'ERGONOMIA', 'QUIMICO', 'BIOLOGICO'] as $riskName) {
            $risks[$riskName] = MtRisk::query()->updateOrCreate(['name' => $riskName], ['active' => true]);
        }
        $sectors = [];
        foreach (['PRODUCAO', 'MANUTENCAO', 'ADMINISTRATIVO'] as $sectorName) {
            $sectors[$sectorName] = MtSector::query()->updateOrCreate(['agreement_id' => $company->id, 'name' => $sectorName], ['active' => true]);
        }
        $functions = [];
        foreach ([['OPERADOR DE PRENSA', 'PRODUCAO', 'RUIDO'], ['MECANICO', 'MANUTENCAO', 'ERGONOMIA'], ['AUXILIAR LABORATORIO', 'PRODUCAO', 'QUIMICO'], ['ANALISTA ADMIN', 'ADMINISTRATIVO', 'BIOLOGICO'], ['SOLDADOR', 'MANUTENCAO', 'RUIDO']] as [$name, $sector, $risk]) {
            $functions[$name] = MtFunction::query()->updateOrCreate(['agreement_id' => $company->id, 'name' => $name], [
                'sector_id' => $sectors[$sector]->id, 'risk_id' => $risks[$risk]->id, 'active' => true,
            ]);
        }
        $patients = Patient::query()->orderBy('name')->get();
        $legacyId = 8000;
        foreach ($patients->take(25) as $patient) {
            $legacyId++;
            $roll = mt_rand(1, 100);
            $functionName = array_keys($functions)[mt_rand(0, count($functions) - 1)];
            $function = $functions[$functionName];
            $status = $roll <= 60 ? AsoStatus::Emitido : ($roll <= 85 ? AsoStatus::Pendente : AsoStatus::Agendado);
            $expires = match (true) {
                $status !== AsoStatus::Emitido => null,
                mt_rand(1, 100) <= 25 => CarbonImmutable::today()->subDays(mt_rand(1, 60))->toDateString(),
                mt_rand(1, 100) <= 50 => CarbonImmutable::today()->addDays(mt_rand(1, 30))->toDateString(),
                default => CarbonImmutable::today()->addDays(mt_rand(45, 330))->toDateString(),
            };
            /** @var MtAso $aso */
            $aso = MtAso::query()->create([
                'patient_id' => $patient->id, 'agreement_id' => $company->id,
                'sector_id' => $function->sector_id, 'function_id' => $function->id,
                'type' => mt_rand(0, 3) === 0 ? AsoType::Admissional : AsoType::Periodico,
                'status' => $status,
                'realized_at' => $status === AsoStatus::Emitido ? CarbonImmutable::today()->subYear()->toDateString() : null,
                'expires_at' => $expires,
                'issued_at' => $status === AsoStatus::Emitido ? now()->subYear() : null,
                'legacy_id' => $legacyId, 'created_by' => $admin->id,
            ]);
            MtAsoEvent::query()->create(['mt_aso_id' => $aso->id, 'event' => MtAsoEvent::CREATED, 'by_user_id' => $admin->id]);
            if ($status === AsoStatus::Emitido) {
                MtAsoEvent::query()->create(['mt_aso_id' => $aso->id, 'event' => MtAsoEvent::ISSUED, 'by_user_id' => $admin->id, 'payload' => ['expires_at' => $expires]]);
                foreach ([MtRisk::query()->where('name', 'RUIDO')->first(), MtRisk::query()->where('name', 'ERGONOMIA')->first()] as $risk) {
                    if ($risk) {
                        DB::table('mt_aso_risks')->insertOrIgnore(['mt_aso_id' => $aso->id, 'risk_id' => $risk->id]);
                    }
                }
            }
        }
    }

    private function seedChat(): void
    {
        $admin = User::query()->where('email', 'mock-admin@dez.local')->firstOrFail();
        $reception = User::query()->where('email', 'mock-recepcao@dez.local')->firstOrFail();
        $cashier = User::query()->where('email', 'mock-caixa@dez.local')->firstOrFail();
        $manager = User::query()->where('email', 'mock-gerente@dez.local')->firstOrFail();
        $doctors = User::query()->whereIn('email', ['mock-medico1@dez.local', 'mock-medico2@dez.local', 'mock-medico3@dez.local'])->get();
        $lines = [
            'Bom dia! A sala 2 está liberada para os exames da manhã.',
            'Recebemos o material do laboratório parceiro, já está separado.',
            'O paciente da 14h confirmou presença por WhatsApp.',
            'Convênio X liberou a autorização da guia que pendia desde ontem.',
            'Fechamento parcial conferido, bateu com o sistema.',
            'Preciso de ajuda com um reagendamento do dia 20.',
            'Autorizado! Pode confirmar com o paciente.',
            'Sugestão: deixar o café da recepção reabastecido antes das 8h.',
            'O equipamento de ultrassom volta da manutenção amanhã.',
            'Aviso: amanhã tem treinamento interno às 16h.',
        ];
        $conversations = [
            [$admin, collect([$reception])],
            [$reception, collect([$cashier])],
            [$cashier, collect([$manager])],
            [$admin, collect([$doctors[0], $doctors[1], $doctors[2]])],
            [$reception, collect([$admin, $cashier, $manager, $doctors[0]])],
        ];
        foreach ($conversations as $index => [$creator, $others]) {
            $group = $others->count() > 1;
            $conversation = ChatConversation::query()->create([
                'type' => $group ? 'GROUP' : 'DIRECT',
                'title' => $group ? ($index === 3 ? 'Equipe Médica' : 'Equipe Recepção') : null,
                'created_by' => $creator->id,
            ]);
            $participants = $others->merge([$creator])->unique('id');
            foreach ($participants as $participant) {
                DB::table('chat_participants')->insert([
                    'id' => (string) Str::uuid(), 'chat_conversation_id' => $conversation->id, 'user_id' => $participant->id,
                    'last_read_at' => now()->subHours($index + 1), 'created_at' => now()->subDays(7), 'updated_at' => now()->subDays(7),
                ]);
            }
            $messageCount = $group ? 12 : 8;
            for ($i = 0; $i < $messageCount; $i++) {
                /** @var User $author */
                $author = $participants[$i % $participants->count()];
                ChatMessage::query()->create([
                    'chat_conversation_id' => $conversation->id, 'user_id' => $author->id,
                    'body' => $lines[($index * 3 + $i) % count($lines)],
                    'created_at' => now()->subDays(6)->addDays($i / 2)->addMinutes($i * 7),
                ]);
            }
        }
    }

    private function seedAttendance(): void
    {
        $users = User::query()->where('email', 'like', 'mock-%@dez.local')->get();
        $today = CarbonImmutable::today((string) config('sistema_dez.timezone'));
        foreach ($users as $user) {
            for ($d = 1; $d <= 14; $d++) {
                $day = $today->subDays($d);
                if ($day->dayOfWeek === 0) {
                    continue;
                }
                AttendanceEvent::query()->create([
                    'user_id' => $user->id, 'service_date' => $day->toDateString(),
                    'logged_in_at' => $day->setTime(7, mt_rand(28, 58)), 'created_at' => $day->setTime(7, mt_rand(28, 58)),
                ]);
            }
        }
    }

    private function seedRoutines(): void
    {
        $admin = User::query()->where('email', 'mock-admin@dez.local')->firstOrFail();
        $reception = User::query()->where('email', 'mock-recepcao@dez.local')->firstOrFail();
        $supervisor = User::query()->where('email', 'mock-supervisor@dez.local')->firstOrFail();
        $tomorrow = CarbonImmutable::tomorrow();
        $today = CarbonImmutable::today((string) config('sistema_dez.timezone'));

        foreach (Appointment::query()->whereDate('scheduled_at', $tomorrow)->where('status', 'CONFIRMED')->with('patient:id,name')->get()->take(8) as $appointment) {
            RoutineTask::query()->create([
                'type' => RoutineTask::CONFIRMACAO, 'ref_type' => 'appointments', 'ref_id' => $appointment->id,
                'title' => 'Confirmar presença: '.($appointment->patient?->name ?? 'paciente'),
                'detail' => 'Consulta marcada para '.$appointment->scheduled_at->format('H:i').'.',
                'assigned_to' => $reception->id, 'due_date' => $today->toDateString(), 'status' => 'PENDENTE', 'created_by' => $admin->id,
            ]);
        }
        foreach (Patient::query()->orderBy('name')->get()->random(5) as $patient) {
            RoutineTask::query()->create([
                'type' => RoutineTask::ANIVERSARIANTE, 'ref_type' => 'patients', 'ref_id' => $patient->id,
                'title' => 'Parabenizar aniversariante: '.$patient->name,
                'assigned_to' => $reception->id, 'due_date' => $today->toDateString(), 'status' => 'PENDENTE', 'created_by' => $admin->id,
            ]);
        }
        foreach (Budget::query()->where('status', 'ENVIADO')->with('patient:id,name')->get()->take(5) as $budget) {
            RoutineTask::query()->create([
                'type' => RoutineTask::ORCAMENTO_RETORNO, 'ref_type' => 'budgets', 'ref_id' => $budget->id,
                'title' => 'Cobrar retorno de orçamento: '.($budget->patient?->name ?? 'paciente'),
                'assigned_to' => $supervisor->id, 'due_date' => $today->addDays(2)->toDateString(), 'status' => 'PENDENTE', 'created_by' => $admin->id,
            ]);
        }
        foreach (MtAso::query()->whereNotNull('expires_at')->where('expires_at', '>=', $today->toDateString())->with('patient:id,name')->orderBy('expires_at')->limit(4)->get() as $aso) {
            RoutineTask::query()->create([
                'type' => RoutineTask::ASO_VENCENDO, 'ref_type' => 'mt_asos', 'ref_id' => $aso->id,
                'title' => 'ASO vencendo: '.($aso->patient?->name ?? 'funcionário'),
                'assigned_to' => $supervisor->id, 'due_date' => $today->toDateString(), 'status' => 'PENDENTE', 'created_by' => $admin->id,
            ]);
        }
        $done = 0;
        foreach (RoutineTask::query()->where('status', 'PENDENTE')->orderBy('created_at')->get() as $task) {
            if ($done >= 5) {
                break;
            }
            $done++;
            $task->forceFill(['status' => 'FEITO', 'done_by' => $reception->id, 'done_at' => now()->subHours($done), 'note' => 'Tratado em contato telefônico.'])->save();
        }
    }

    private function seedAuditLogs(): void
    {
        $admin = User::query()->where('email', 'mock-admin@dez.local')->firstOrFail();
        $actions = [
            ['catalog.procedure_updated', 'procedures'], ['catalog.agreement_updated', 'agreements'],
            ['team.user_created', 'users'], ['schedule.window_created', 'service_offerings'],
            ['cash.register_opened', 'cash_registers'], ['payment.charged', 'appointments'],
            ['queue.called', 'queue_entries'], ['report.finalized', 'reports'],
            ['budget.sent', 'budgets'], ['payout.paid', 'payouts'],
        ];
        for ($i = 0; $i < 40; $i++) {
            [$action, $entity] = $actions[$i % count($actions)];
            AuditLog::query()->create([
                'entity_type' => $entity, 'entity_id' => (string) Str::uuid(),
                'action' => $action, 'actor_type' => 'USER', 'actor_id' => $admin->id, 'ip_address' => '127.0.0.1',
                'before' => null, 'after' => ['mock' => true], 'metadata' => ['seeded_by' => 'MockDataSeeder'],
                'created_at' => now()->subDays($i % 30)->subHours($i % 24),
            ]);
        }
    }
}
