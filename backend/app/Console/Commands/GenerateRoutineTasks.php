<?php

namespace App\Console\Commands;

use App\Services\RoutineGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('rotinas:generate {--date= : Data de referência (Y-m-d, padrão hoje)}')]
#[Description('Gera as rotinas do dia (confirmações, orçamentos, aniversariantes, faturas)')]
class GenerateRoutineTasks extends Command
{
    public function handle(RoutineGenerator $generator): int
    {
        $date = CarbonImmutable::parse($this->option('date') ?? CarbonImmutable::today(config('sistema_dez.timezone'))->toDateString());
        $created = $generator->generate($date);
        $this->info("Rotinas geradas para {$date->toDateString()}: {$created} nova(s).");

        return self::SUCCESS;
    }
}
