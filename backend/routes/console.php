<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('schedule:materialize')->dailyAt('00:10')->timezone('America/Fortaleza')->withoutOverlapping();
Schedule::command('rotinas:generate')->dailyAt('00:20')->timezone('America/Fortaleza')->withoutOverlapping();
Schedule::command('whatsapp:dispatch-confirmations')->dailyAt((string) config('sistema_dez.whatsapp.reminder_hour', '10:00'))->timezone('America/Fortaleza')->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
