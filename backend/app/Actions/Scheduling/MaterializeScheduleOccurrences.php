<?php

namespace App\Actions\Scheduling;

use App\Enums\BookingMode;
use App\Models\ScheduleOccurrence;
use App\Models\ServiceOffering;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class MaterializeScheduleOccurrences
{
    public function handle(ServiceOffering $offering, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): int
    {
        $timezone = $offering->unit->timezone;
        $from ??= CarbonImmutable::today($timezone);
        $to ??= $from->addDays(89);

        return DB::transaction(function () use ($offering, $from, $to): int {
            $offering = ServiceOffering::query()->lockForUpdate()->findOrFail($offering->id);
            $offering->load(['procedure', 'unit', 'scheduleWindows', 'scheduleExceptions']);
            $existing = ScheduleOccurrence::query()->where('service_offering_id', $offering->id)
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->orderBy('id')->lockForUpdate()->get();
            ScheduleOccurrence::query()->whereIn('id', $existing->modelKeys())->update(['status' => 'CLOSED']);
            if (! $offering->active || ! $offering->unit->active || $offering->booking_mode === BookingMode::WalkInInfo) {
                return 0;
            }
            $count = 0;
            for ($date = $from->startOfDay(); $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
                foreach ($offering->scheduleWindows as $window) {
                    if (! $window->active || $window->weekday !== $date->dayOfWeek || $window->effective_from->toDateString() > $date->toDateString()
                        || ($window->effective_to && $window->effective_to->toDateString() < $date->toDateString())) {
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
                        $occurrence = ScheduleOccurrence::query()->firstOrNew([
                            'service_offering_id' => $offering->id,
                            'date' => $date->toDateString(),
                            'starts_at' => $start->format('H:i:s'),
                        ]);
                        $booked = $occurrence->exists ? $occurrence->appointments()->whereIn('status', ['CONFIRMED', 'CHECKED_IN'])->count() : 0;
                        if ($booked > 0 && ($occurrence->ends_at !== $slotEnd->format('H:i:s') || $capacity < $booked)) {
                            continue;
                        }
                        $occurrence->fill(['schedule_window_id' => $window->id, 'ends_at' => $slotEnd->format('H:i:s'), 'capacity' => $capacity, 'status' => 'OPEN'])->save();
                        $count++;
                    }
                }
            }

            return $count;
        }, 3);
    }
}
