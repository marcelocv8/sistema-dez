<?php

namespace Database\Seeders;

use App\Enums\BookingMode;
use App\Enums\Modality;
use App\Enums\UserRole;
use App\Models\Agreement;
use App\Models\Appointment;
use App\Models\CatalogLink;
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
        if (! app()->environment('testing') || config('database.connections.pgsql.database') !== 'sistema_dez_test') {
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
                'status' => 'CONFIRMED', 'kind' => 'NEW', 'origin' => 'INTERNAL', 'price_snapshot' => '150.00',
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
