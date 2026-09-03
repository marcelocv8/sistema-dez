<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\CatalogLink;
use App\Models\ProcedurePrice;
use App\Models\Professional;
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
        if (CatalogLink::query()->whereHas('professional', fn ($query) => $query->where('name', 'PROFISSIONAL PILOTO E2E'))->exists()) {
            return;
        }
        $price = ProcedurePrice::factory()->create(['amount' => '150.00']);
        $price->unit->update(['name' => 'CLINICA DEZ MESSEJANA', 'operational_enabled' => true]);
        $price->procedure->update(['name' => 'CONSULTA PILOTO E2E']);
        $price->agreement->update(['name' => 'PARTICULAR PILOTO']);
        $professional = Professional::factory()->create(['name' => 'PROFISSIONAL PILOTO E2E']);
        CatalogLink::create(['unit_id' => $price->unit_id, 'procedure_id' => $price->procedure_id, 'professional_id' => $professional->id, 'agreement_id' => $price->agreement_id, 'active' => true]);
    }
}
