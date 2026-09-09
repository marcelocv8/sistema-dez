<?php

namespace Database\Seeders;

use App\Actions\Scheduling\MaterializeScheduleOccurrences;
use App\Enums\AsoStatus;
use App\Enums\AsoType;
use App\Enums\BookingMode;
use App\Enums\Modality;
use App\Enums\UserRole;
use App\Models\Agreement;
use App\Models\Appointment;
use App\Models\CatalogLink;
use App\Models\LoyaltyPlan;
use App\Models\MtAso;
use App\Models\MtFunction;
use App\Models\MtRisk;
use App\Models\MtSector;
use App\Models\Patient;
use App\Models\ProcedurePrice;
use App\Models\Professional;
use App\Models\ReportTemplate;
use App\Models\ServiceOffering;
use App\Models\User;
use Illuminate\Database\Seeder;

class SchedulingE2ESeeder extends Seeder
{
    public function run(): void
    {
        $database = (string) config('database.connections.pgsql.database');
        $allowed = ['sistema_dez_test'];
        $override = getenv('E2E_DB_NAME');
        if (is_string($override) && str_starts_with($override, 'sistema_dez_test')) {
            $allowed[] = $override;
        }
        if (! app()->environment('testing') || ! in_array($database, $allowed, true)) {
            throw new \RuntimeException('Fixture permitida apenas no banco isolado de testes.');
        }
        $password = getenv('E2E_PASSWORD');
        if (! is_string($password) || strlen($password) < 12) {
            throw new \RuntimeException('Defina E2E_PASSWORD com pelo menos 12 caracteres.');
        }
        User::updateOrCreate(['email' => 'admin-piloto@example.test'], ['name' => 'Admin do piloto de testes', 'role' => UserRole::Admin, 'active' => true, 'password' => $password]);
        User::updateOrCreate(['email' => 'recepcao-piloto@example.test'], ['name' => 'Recepção do piloto de testes', 'role' => UserRole::Reception, 'active' => true, 'password' => $password]);
        User::updateOrCreate(['email' => 'caixa-piloto@example.test'], ['name' => 'Caixa do piloto de testes', 'role' => UserRole::Cashier, 'active' => true, 'password' => $password]);
        $doctor = User::updateOrCreate(['email' => 'medico-piloto@example.test'], ['name' => 'Médico do piloto de testes', 'role' => UserRole::Doctor, 'active' => true, 'password' => $password]);
        if (CatalogLink::query()->whereHas('professional', fn ($query) => $query->where('name', 'PROFISSIONAL PILOTO E2E'))->exists()) {
            $pilotProfessional = Professional::query()->where('name', 'PROFISSIONAL PILOTO E2E')->first();
            if ($pilotProfessional && $pilotProfessional->user_id === null) {
                $pilotProfessional->update(['user_id' => $doctor->id]);
            }
            $this->seedB2bCompany();
            $this->seedPilotPatient();
            $link = CatalogLink::query()->whereHas('professional', fn ($query) => $query->where('name', 'PROFISSIONAL PILOTO E2E'))->first();
            if ($link !== null) {
                $this->seedReportTemplates($link->procedure_id, $link->professional_id);
                $this->seedPilotAppointment($link);
                $this->seedLoyalty($link);
                $this->seedMt($link);
            }

            return;
        }
        $price = ProcedurePrice::factory()->create(['amount' => '150.00']);
        $price->unit->update(['name' => 'CLINICA DEZ MESSEJANA', 'operational_enabled' => true]);
        $price->procedure->update(['name' => 'CONSULTA PILOTO E2E']);
        $price->agreement->update(['name' => 'PARTICULAR PILOTO', 'is_default' => true, 'requires_authorization' => false]);
        $professional = Professional::factory()->create(['name' => 'PROFISSIONAL PILOTO E2E', 'user_id' => $doctor->id]);
        $link = CatalogLink::create(['unit_id' => $price->unit_id, 'procedure_id' => $price->procedure_id, 'professional_id' => $professional->id, 'agreement_id' => $price->agreement_id, 'active' => true]);
        $this->seedB2bCompany();
        $this->seedPilotPatient();
        $this->seedReportTemplates($price->procedure_id, $professional->id);
        $this->seedPilotAppointment($link);
        $this->seedLoyalty($link);
        $this->seedMt($link);
    }

    private function seedMt(CatalogLink $base): void
    {
        if (Agreement::query()->where('name', 'EMPRESA MT PILOTO E2E')->exists()) {
            return;
        }
        $company = Agreement::factory()->create(['name' => 'EMPRESA MT PILOTO E2E', 'type' => 'B2B', 'is_default' => false, 'requires_authorization' => false, 'active' => true]);
        ProcedurePrice::factory()->create(['procedure_id' => $base->procedure_id, 'agreement_id' => $company->id, 'unit_id' => $base->unit_id, 'amount' => '150.00']);
        CatalogLink::create(['unit_id' => $base->unit_id, 'procedure_id' => $base->procedure_id,
            'professional_id' => $base->professional_id, 'agreement_id' => $company->id, 'active' => true]);

        $risk = MtRisk::query()->updateOrCreate(['name' => 'RUIDO'], ['active' => true]);
        $sector = MtSector::query()->updateOrCreate(['agreement_id' => $company->id, 'name' => 'PRODUCAO MT'], ['active' => true]);
        MtFunction::query()->updateOrCreate(['agreement_id' => $company->id, 'name' => 'OPERADOR MT'], ['sector_id' => $sector->id, 'risk_id' => $risk->id, 'active' => true]);

        $admin = User::query()->where('email', 'admin-piloto@example.test')->firstOrFail();
        foreach ([
            ['cpf' => '12345678909', 'name' => 'Funcionario MT Vencido E2E', 'last_four' => '8909', 'expires_at' => now()->subDays(10)->toDateString()],
            ['cpf' => '98765432100', 'name' => 'Funcionario MT A Vencer E2E', 'last_four' => '2100', 'expires_at' => now()->addDays(20)->toDateString()],
        ] as $employee) {
            $hash = hash_hmac('sha256', $employee['cpf'], (string) config('sistema_dez.identity_pepper'));
            $patient = Patient::query()->updateOrCreate(
                ['cpf_hash' => $hash],
                ['name' => $employee['name'], 'cpf_last_four' => $employee['last_four'], 'phone' => '85999990000',
                    'birth_date' => '1988-03-10', 'privacy_consent_at' => now(), 'privacy_consent_source' => 'E2E'],
            );
            MtAso::query()->updateOrCreate(['legacy_id' => $employee['last_four'] === '8909' ? 9001 : 9002], [
                'patient_id' => $patient->id, 'agreement_id' => $company->id, 'sector_id' => $sector->id,
                'type' => AsoType::Periodico, 'status' => AsoStatus::Emitido,
                'realized_at' => now()->subYear()->toDateString(), 'expires_at' => $employee['expires_at'],
                'issued_at' => now(), 'created_by' => $admin->id,
            ]);
        }
    }

    private function seedLoyalty(CatalogLink $base): void
    {
        foreach ([
            ['cpf' => '16899535009', 'name' => 'Assinante Sou DEZ E2E', 'last_four' => '5009'],
            ['cpf' => '35172267697', 'name' => 'Dependente Sou DEZ E2E', 'last_four' => '7697'],
        ] as $subscriber) {
            $hash = hash_hmac('sha256', $subscriber['cpf'], (string) config('sistema_dez.identity_pepper'));
            Patient::query()->updateOrCreate(
                ['cpf_hash' => $hash],
                ['name' => $subscriber['name'], 'cpf_last_four' => $subscriber['last_four'], 'phone' => '85999990000',
                    'birth_date' => '1985-06-15', 'privacy_consent_at' => now(), 'privacy_consent_source' => 'E2E'],
            );
        }
        if (Agreement::query()->where('name', 'SOU DEZ PILOTO E2E')->doesntExist()) {
            $agreement = Agreement::factory()->create(['name' => 'SOU DEZ PILOTO E2E', 'type' => 'PLAN', 'is_default' => false, 'requires_authorization' => false, 'active' => true]);
            $price = ProcedurePrice::factory()->create(['procedure_id' => $base->procedure_id, 'agreement_id' => $agreement->id, 'unit_id' => $base->unit_id, 'amount' => '65.00']);
            $link = CatalogLink::create(['unit_id' => $base->unit_id, 'procedure_id' => $base->procedure_id,
                'professional_id' => $base->professional_id, 'agreement_id' => $agreement->id, 'active' => true]);
            $offering = ServiceOffering::query()->updateOrCreate(
                ['procedure_id' => $base->procedure_id, 'professional_id' => $base->professional_id, 'agreement_id' => $agreement->id],
                ['unit_id' => $base->unit_id, 'catalog_link_id' => $link->id, 'procedure_price_id' => $price->id,
                    'booking_mode' => BookingMode::FixedSlot, 'modality' => Modality::Presential,
                    'price' => '65.00', 'arrival_block_minutes' => 60, 'arrival_block_capacity' => 3,
                    'online_enabled' => false, 'active' => true],
            );
            foreach ([1, 2, 3, 4, 5, 6, 0] as $weekday) {
                $offering->scheduleWindows()->updateOrCreate(
                    ['weekday' => $weekday, 'starts_at' => '08:00:00'],
                    ['effective_from' => now()->toDateString(), 'effective_to' => null, 'ends_at' => '10:00:00',
                        'slot_minutes' => 30, 'daily_capacity_override' => null, 'active' => true],
                );
            }
            app(MaterializeScheduleOccurrences::class)->handle($offering);
            LoyaltyPlan::query()->updateOrCreate(['agreement_id' => $agreement->id], [
                'name' => 'SOU DEZ COMPLETO E2E', 'monthly_fee' => '89.90', 'due_day' => 10, 'active' => true,
            ]);
        }
    }

    private function seedPilotAppointment(CatalogLink $link): void
    {
        $patient = Patient::query()->where('name', 'Paciente Piloto E2E')->firstOrFail();
        $offering = ServiceOffering::query()->updateOrCreate(
            ['procedure_id' => $link->procedure_id, 'professional_id' => $link->professional_id, 'agreement_id' => $link->agreement_id],
            ['unit_id' => $link->unit_id, 'booking_mode' => BookingMode::FixedSlot, 'modality' => Modality::Presential,
                'price' => '150.00', 'arrival_block_minutes' => 60, 'arrival_block_capacity' => 3,
                'online_enabled' => true, 'active' => true],
        );
        Appointment::query()->updateOrCreate(
            ['patient_id' => $patient->id, 'service_offering_id' => $offering->id],
            ['scheduled_at' => now(config('sistema_dez.timezone'))->addDay()->setTimeFromTimeString('09:00')->utc(),
                'recommended_arrival_at' => now(config('sistema_dez.timezone'))->addDay()->setTimeFromTimeString('08:30')->utc(),
                'status' => 'CHECKED_IN', 'payment_status' => 'PAID', 'care_started_at' => now(), 'kind' => 'NEW', 'origin' => 'INTERNAL', 'price_snapshot' => '150.00',
                'idempotency_key' => '00000000-0000-4000-8000-0000000000e2'],
        );
    }

    private function seedReportTemplates(string $procedureId, ?string $professionalId): void
    {
        ReportTemplate::query()->updateOrCreate(['legacy_type' => 'LAUDO', 'legacy_id' => 704], [
            'nome' => 'ECO PILOTO E2E',
            'content_html' => '<h4>ECODOPPLERCARDIOGRAMA TRANSTORÁCICO</h4><p>Paciente: {{PACIENTE_NOME}}, {{PACIENTE_IDADE}} anos.</p><p>Data: {{DATA_HOJE}}</p><p>CONCLUSÃO: exame dentro da normalidade.</p>',
            'legacy_medico' => 'ADMINISTRADOR',
            'legacy_procedure' => 'CONSULTA PILOTO E2E',
            'doctor_id' => $professionalId,
            'procedure_id' => $procedureId,
            'active' => true,
            'imported_at' => now(),
        ]);
        ReportTemplate::query()->updateOrCreate(['legacy_type' => 'LINHA', 'legacy_id' => 928], [
            'nome' => 'BETA- HCG',
            'content_html' => '<p><strong>RESULTADO:</strong> REAGENTE</p><p>Material : Soro</p>',
            'legacy_medico' => 'ADMINISTRADOR',
            'legacy_procedure' => 'BETA HCG QUALITATIVO',
            'active' => true,
            'imported_at' => now(),
        ]);
    }

    private function seedPilotPatient(): void
    {
        $cpf = '11144477735';
        $hash = hash_hmac('sha256', $cpf, (string) config('sistema_dez.identity_pepper'));
        Patient::query()->updateOrCreate(
            ['cpf_hash' => $hash],
            ['name' => 'Paciente Piloto E2E', 'cpf_last_four' => '7735', 'phone' => '85999990000',
                'birth_date' => '1990-01-01', 'privacy_consent_at' => now(), 'privacy_consent_source' => 'E2E'],
        );
    }

    private function seedB2bCompany(): void
    {
        $base = CatalogLink::query()->whereHas('professional', fn ($query) => $query->where('name', 'PROFISSIONAL PILOTO E2E'))
            ->whereHas('agreement', fn ($query) => $query->where('name', 'PARTICULAR PILOTO'))->firstOrFail();
        foreach (['EMPRESA PILOTO E2E', 'EMPRESA PILOTO E2E M'] as $name) {
            if (Agreement::query()->where('name', $name)->exists()) {
                continue;
            }
            $company = Agreement::factory()->create(['name' => $name, 'type' => 'B2B', 'is_default' => false, 'requires_authorization' => false, 'active' => true]);
            ProcedurePrice::factory()->create([
                'procedure_id' => $base->procedure_id, 'agreement_id' => $company->id, 'unit_id' => $base->unit_id, 'amount' => '150.00',
            ]);
            CatalogLink::create(['unit_id' => $base->unit_id, 'procedure_id' => $base->procedure_id,
                'professional_id' => $base->professional_id, 'agreement_id' => $company->id, 'active' => true]);
        }
    }
}
