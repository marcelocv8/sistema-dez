<?php

namespace App\Console\Commands;

use App\Actions\Scheduling\MaterializeScheduleOccurrences;
use App\Actions\Scheduling\RefreshOfferingVisibility;
use App\Models\ServiceOffering;
use Illuminate\Console\Command;

class MaterializeSchedules extends Command
{
    protected $signature = 'schedule:materialize {--offering= : ID da oferta}';

    protected $description = 'Materializa de forma idempotente os próximos 90 dias de agenda';

    public function handle(MaterializeScheduleOccurrences $materialize, RefreshOfferingVisibility $visibility): int
    {
        $count = 0;
        ServiceOffering::query()->when($this->option('offering'), fn ($query, $id) => $query->whereKey($id))
            ->whereHas('unit', fn ($query) => $query->where('active', true)->where('operational_enabled', true))
            ->eachById(function (ServiceOffering $offering) use ($materialize, &$count): void {
                $count += $materialize->handle($offering);
            });
        // A visibilidade roda sobre TODAS as ofertas: é ela que tira do ar
        // ofertas cuja unidade, vínculo, preço ou escala perderam elegibilidade.
        ServiceOffering::query()->when($this->option('offering'), fn ($query, $id) => $query->whereKey($id))
            ->eachById(function (ServiceOffering $offering) use ($visibility): void {
                $visibility->handle($offering);
            });
        $this->info("{$count} ocorrências abertas na janela de 90 dias.");

        return self::SUCCESS;
    }
}
