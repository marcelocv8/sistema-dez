<?php

namespace App\Actions\Scheduling;

use App\Enums\BookingMode;
use App\Models\Appointment;
use App\Models\ScheduleOccurrence;
use App\Models\ServiceOffering;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MaterializeScheduleOccurrences
{
    /**
     * Number of rows per upsert batch. Keeps every statement well below
     * PostgreSQL's bind-parameter limit even for dense schedules.
     */
    private const UPSERT_CHUNK = 500;

    public function handle(ServiceOffering $offering, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): int
    {
        $offering->loadMissing('unit');
        $timezone = $offering->unit->timezone;
        $from ??= CarbonImmutable::today($timezone);
        $to ??= $from->addDays(89);

        return DB::transaction(function () use ($offering, $from, $to): int {
            $offering = ServiceOffering::query()->lockForUpdate()->findOrFail($offering->id);
            $offering->load(['procedure', 'unit',
                'scheduleWindows' => fn ($query) => $query->orderBy('starts_at')->orderBy('ends_at'),
                'scheduleExceptions']);

            /** @var Collection<string, ScheduleOccurrence> $existing */
            $existing = ScheduleOccurrence::query()->where('service_offering_id', $offering->id)
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->orderBy('id')->lockForUpdate()->get()
                ->keyBy(fn (ScheduleOccurrence $occurrence): string => $occurrence->date->toDateString().'|'.$occurrence->starts_at);

            ScheduleOccurrence::query()->whereIn('id', $existing->modelKeys())->update(['status' => 'CLOSED']);

            if (! $offering->active || ! $offering->unit->active || $offering->booking_mode === BookingMode::WalkInInfo) {
                return 0;
            }

            $existingBooked = Appointment::query()
                ->whereIn('schedule_occurrence_id', $existing->modelKeys())
                ->whereIn('status', ['CONFIRMED', 'CHECKED_IN'])
                ->selectRaw('schedule_occurrence_id, count(*) as aggregate')
                ->groupBy('schedule_occurrence_id')
                ->pluck('aggregate', 'schedule_occurrence_id');

            /**
             * Indexado por "data|starts_at": é a chave do upsert
             * (service_offering_id, date, starts_at). Janelas sobrepostas da
             * mesma oferta propõem o mesmo slot e o PostgreSQL recusa duas
             * linhas com a mesma chave no mesmo comando ("ON CONFLICT DO
             * UPDATE command cannot affect row a second time"). A primeira
             * janela (ordem de starts_at) vence; a comparação com as consultas
             * já marcadas continua valendo para cada candidata.
             *
             * @var array<string, array<string, mixed>>
             */
            $rows = [];
            for ($date = $from->startOfDay(); $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
                $dateKey = $date->toDateString();
                foreach ($offering->scheduleWindows as $window) {
                    if (! $window->active || $window->weekday !== $date->dayOfWeek || $window->effective_from->toDateString() > $dateKey
                        || ($window->effective_to && $window->effective_to->toDateString() < $dateKey)) {
                        continue;
                    }
                    $minutes = $offering->booking_mode === BookingMode::ArrivalBlock ? $offering->arrival_block_minutes
                        : ($window->slot_minutes ?? $offering->duration_override_minutes ?? $offering->procedure->duration_minutes);
                    $capacity = $offering->booking_mode === BookingMode::ArrivalBlock ? $offering->arrival_block_capacity : 1;
                    if ($minutes < 1 || $capacity < 1) {
                        continue;
                    }
                    $end = $date->setTimeFromTimeString($window->ends_at);
                    for ($start = $date->setTimeFromTimeString($window->starts_at); $start->addMinutes($minutes)->lessThanOrEqualTo($end); $start = $start->addMinutes($minutes)) {
                        $slotEnd = $start->addMinutes($minutes);
                        $blocked = $offering->scheduleExceptions->contains(function ($exception) use ($date, $start, $slotEnd): bool {
                            if (! $exception->blocked || $exception->date->toDateString() !== $date->toDateString()) {
                                return false;
                            }

                            return ! $exception->starts_at || ($start->lessThan($date->setTimeFromTimeString($exception->ends_at))
                                && $slotEnd->greaterThan($date->setTimeFromTimeString($exception->starts_at)));
                        });
                        if ($blocked) {
                            continue;
                        }

                        $startsAt = $start->format('H:i:s');
                        $endsAt = $slotEnd->format('H:i:s');
                        $occurrence = $existing->get($dateKey.'|'.$startsAt);
                        $row = [
                            'id' => $occurrence?->id ?? (string) Str::uuid(),
                            'service_offering_id' => $offering->id,
                            'schedule_window_id' => $window->id,
                            'date' => $dateKey,
                            'starts_at' => $startsAt,
                            'ends_at' => $endsAt,
                            'capacity' => $capacity,
                            'status' => 'OPEN',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];

                        if ($occurrence) {
                            $booked = (int) ($existingBooked[$occurrence->id] ?? 0);
                            if ($booked > 0 && ($occurrence->ends_at !== $endsAt || $capacity < $booked)) {
                                continue;
                            }
                        }

                        $rows[$dateKey.'|'.$startsAt] ??= $row;
                    }
                }
            }

            foreach (array_chunk($rows, self::UPSERT_CHUNK) as $chunk) {
                ScheduleOccurrence::query()->upsert(
                    $chunk,
                    ['service_offering_id', 'date', 'starts_at'],
                    ['schedule_window_id', 'ends_at', 'capacity', 'status', 'updated_at'],
                );
            }

            return count($rows);
        }, 3);
    }
}
