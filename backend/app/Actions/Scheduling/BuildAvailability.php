<?php

namespace App\Actions\Scheduling;

use App\Enums\BookingMode;
use App\Models\Appointment;
use App\Models\ScheduleException;
use App\Models\ScheduleWindow;
use App\Models\ServiceOffering;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class BuildAvailability
{
    /** @param array<string, mixed> $filters */
    public function handle(array $filters): array
    {
        $timezone = config('sistema_dez.timezone');
        $from = CarbonImmutable::createFromFormat('Y-m-d', $filters['from'], $timezone)->startOfDay();
        $to = CarbonImmutable::createFromFormat('Y-m-d', $filters['to'], $timezone)->startOfDay();

        $offerings = ServiceOffering::query()
            ->when($filters['internal'] ?? false, fn (Builder $query) => $query->where('active', true)
                ->whereHas('unit', fn (Builder $unit) => $unit->where('active', true)->where('operational_enabled', true))
                ->whereHas('catalogLink', fn ($link) => $link->where('active', true))
                ->whereHas('procedure', fn ($procedure) => $procedure->where('active', true))
                ->whereHas('professional', fn ($professional) => $professional->where('active', true))
                ->whereHas('agreement', fn ($agreement) => $agreement->where('active', true)),
                fn (Builder $query) => $query->publiclyAvailable())
            ->when($filters['offering_id'] ?? null, fn (Builder $query, string $id) => $query->whereKey($id))
            ->where('procedure_id', $filters['procedure_id'])
            ->where('unit_id', $filters['unit_id'])
            ->where('agreement_id', $filters['agreement_id'])
            ->where('modality', $filters['modality'])
            ->when($filters['professional_id'] ?? null, fn (Builder $query, string $id) => $query->where('professional_id', $id))
            ->with([
                'procedure', 'professional',
                'scheduleWindows' => fn ($query) => $query->where('active', true),
                'scheduleExceptions' => fn ($query) => $query->where('blocked', true)->whereBetween('date', [$from->toDateString(), $to->toDateString()]),
            ])
            ->get();

        $bookings = Appointment::query()->with(['serviceOffering.procedure', 'scheduleOccurrence'])
            ->whereIn('status', ['CONFIRMED', 'CHECKED_IN'])
            ->when($filters['exclude_appointment_id'] ?? null, fn ($query, $id) => $query->where('id', '!=', $id))
            ->whereBetween('scheduled_at', [$from->utc(), $to->endOfDay()->utc()])
            ->whereHas('serviceOffering', fn ($query) => $query->whereIn('id', $offerings->modelKeys())
                ->orWhereIn('professional_id', $offerings->pluck('professional_id')->filter()->unique()))->get();
        foreach ($offerings as $offering) {
            $offering->setRelation('appointments', $bookings->filter(fn ($booking) => $offering->professional_id
                ? $booking->serviceOffering->professional_id === $offering->professional_id
                : $booking->service_offering_id === $offering->id));
        }

        $result = [];
        for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
            foreach ($offerings as $offering) {
                if (! ($filters['internal'] ?? false) && ($date->lessThan(CarbonImmutable::today($timezone))
                    || $date->greaterThan(CarbonImmutable::today($timezone)->addDays(config('sistema_dez.public_booking_horizon_days') - 1))
                    || $date->toDateString() > $offering->published_until->toDateString())) {
                    continue;
                }
                $result = [...$result, ...$this->forDate($offering, $date, $timezone, ! ($filters['internal'] ?? false))];
            }
        }

        return $result;
    }

    private function forDate(ServiceOffering $offering, CarbonImmutable $date, string $timezone, bool $public): array
    {
        $windows = $offering->scheduleWindows->filter(fn (ScheduleWindow $window) => $window->weekday === $date->dayOfWeek
            && $window->effective_from->startOfDay()->lessThanOrEqualTo($date)
            && (! $window->effective_to || $window->effective_to->endOfDay()->greaterThanOrEqualTo($date))
        );
        $exceptions = $offering->scheduleExceptions->filter(fn (ScheduleException $exception) => $exception->date->isSameDay($date));
        if ($exceptions->contains(fn (ScheduleException $exception) => ! $exception->starts_at && ! $exception->ends_at)) {
            return [];
        }

        $bookings = $offering->appointments->filter(fn ($appointment) => $appointment->scheduled_at->setTimezone($timezone)->isSameDay($date));
        $rows = [];
        foreach ($windows as $window) {
            $start = $date->setTimeFromTimeString($window->starts_at);
            $end = $date->setTimeFromTimeString($window->ends_at);
            $operatingWindow = ['starts_at' => $start->toIso8601String(), 'ends_at' => $end->toIso8601String()];

            if ($offering->booking_mode === BookingMode::WalkInInfo) {
                $rows[] = $this->row($offering, $date, $operatingWindow, []);

                continue;
            }

            $slots = $this->slots($offering, $window, $start, $end, $bookings, $exceptions, $timezone);
            $slots = $slots->where('remaining_capacity', '>', 0)->values();
            if ($public) {
                $slots = $slots->filter(fn ($slot) => CarbonImmutable::parse($slot['starts_at'])->greaterThanOrEqualTo(now()->addMinutes(config('sistema_dez.public_booking_lead_minutes'))))->values();
            }
            if ($offering->booking_mode === BookingMode::ArrivalBlock) {
                $slots = $slots->take(1)->values();
            }
            if ($slots->isNotEmpty()) {
                $rows[] = $this->row($offering, $date, $operatingWindow, $slots->all());
            }
        }

        return $rows;
    }

    private function slots(ServiceOffering $offering, ScheduleWindow $window, CarbonImmutable $start, CarbonImmutable $end, Collection $bookings, Collection $exceptions, string $timezone): Collection
    {
        $minutes = $offering->booking_mode === BookingMode::ArrivalBlock
            ? $offering->arrival_block_minutes
            : ($window->slot_minutes ?? $offering->duration_override_minutes ?? $offering->procedure->duration_minutes);
        $capacity = $offering->booking_mode === BookingMode::ArrivalBlock ? $offering->arrival_block_capacity : 1;
        $dailyCapacity = $window->daily_capacity_override ?? $offering->daily_capacity;
        if ($dailyCapacity !== null && $bookings->count() >= $dailyCapacity) {
            return collect();
        }

        $slots = collect();
        for ($cursor = $start; $cursor->addMinutes($minutes)->lessThanOrEqualTo($end); $cursor = $cursor->addMinutes($minutes)) {
            $slotEnd = $cursor->addMinutes($minutes);
            $blocked = $exceptions->contains(function (ScheduleException $exception) use ($cursor, $slotEnd): bool {
                if (! $exception->starts_at || ! $exception->ends_at) {
                    return true;
                }
                $exceptionStart = $cursor->startOfDay()->setTimeFromTimeString($exception->starts_at);
                $exceptionEnd = $cursor->startOfDay()->setTimeFromTimeString($exception->ends_at);

                return $cursor->lessThan($exceptionEnd) && $slotEnd->greaterThan($exceptionStart);
            });
            if ($blocked) {
                continue;
            }

            $used = $bookings->filter(function ($appointment) use ($cursor, $slotEnd, $timezone): bool {
                $start = $appointment->scheduled_at->setTimezone($timezone);
                $end = $appointment->scheduleOccurrence ? $start->setTimeFromTimeString($appointment->scheduleOccurrence->ends_at)
                    : $start->addMinutes($appointment->serviceOffering->duration_override_minutes ?? $appointment->serviceOffering->procedure->duration_minutes);

                return $start->lessThan($slotEnd) && $end->greaterThan($cursor);
            })->count();
            $remaining = max(0, $capacity - $used);
            if ($dailyCapacity !== null) {
                $remaining = min($remaining, max(0, $dailyCapacity - $bookings->count()));
            }
            $slots->push([
                'starts_at' => $cursor->toIso8601String(),
                'ends_at' => $slotEnd->toIso8601String(),
                'recommended_arrival_at' => $cursor->toIso8601String(),
                'remaining_capacity' => $remaining,
            ]);
        }

        return $slots;
    }

    private function row(ServiceOffering $offering, CarbonImmutable $date, array $operatingWindow, array $slots): array
    {
        return [
            'date' => $date->toDateString(),
            'offering_id' => $offering->id,
            'booking_mode' => $offering->booking_mode->value,
            'professional' => $offering->professional ? ['id' => $offering->professional->id, 'name' => $offering->professional->name] : null,
            'operating_window' => $operatingWindow,
            'slots' => $slots,
        ];
    }
}
