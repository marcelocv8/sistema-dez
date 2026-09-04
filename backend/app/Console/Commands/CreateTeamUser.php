<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CreateTeamUser extends Command
{
    protected $signature = 'team:user
        {email : E-mail de acesso}
        {--name= : Nome completo}
        {--role=RECEPTION : ADMIN, SUPERVISOR, RECEPTION, CASHIER, MANAGER ou DOCTOR}
        {--password= : Senha; se omitida, será solicitada sem eco}';

    protected $description = 'Cria ou atualiza um usuário da equipe sem senha padrão';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $name = trim((string) ($this->option('name') ?: ''));
        $role = mb_strtoupper(trim((string) $this->option('role')));
        $password = (string) ($this->option('password') ?: $this->secret('Senha'));

        $validator = Validator::make(compact('email', 'name', 'role', 'password'), [
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'password' => ['required', 'string', 'max:255', Password::min(8)->letters()->numbers()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::INVALID;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'role' => UserRole::from($role), 'password' => $password, 'active' => true],
        );

        $this->info("Usuário {$user->email} salvo com perfil {$user->role->value}.");

        return self::SUCCESS;
    }
}
