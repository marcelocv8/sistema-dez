<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\B2bInvoice;
use App\Models\CashRegister;
use App\Models\Payout;
use App\Models\QueueEntry;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $date = CarbonImmutable::today((string) config('sistema_dez.timezone'));

        $counts = QueueEntry::query()
            ->whereDate('service_date', $date)
            ->get(['status'])
            ->countBy(fn (QueueEntry $entry): string => $this->flowBucket($entry->status));

        $flow = [
            'waiting' => (int) ($counts->get('waiting', 0)),
            'in_care' => (int) ($counts->get('in_care', 0)),
            'checkout' => (int) ($counts->get('checkout', 0)),
            'finished' => (int) ($counts->get('finished', 0)),
        ];

        $queue = QueueEntry::query()
            ->with(['appointment.patient:id,name', 'serviceOffering.procedure:id,name'])
            ->whereDate('service_date', $date)
            ->orderBy('priority_rank')
            ->orderBy('checked_in_at')
            ->limit(5)
            ->get()
            ->map(fn (QueueEntry $entry): array => [
                'id' => $entry->id,
                'patient_name' => $entry->appointment?->patient?->name,
                'procedure_name' => $entry->serviceOffering->procedure?->name,
                'checked_in_at' => $entry->checked_in_at?->toIso8601String(),
                'status' => $entry->status,
            ])
            ->all();

        $revenue = (float) Appointment::query()->where('payment_status', 'PAID')
            ->whereDate('paid_at', $date)->sum('price_snapshot');
        $cashDifference = (float) CashRegister::query()->where('status', 'FECHADO')
            ->whereDate('closed_at', $date)->sum('cash_difference');
        $commissions = (float) Payout::query()->whereIn('status', ['OPEN', 'CONFIRMED', 'CONTESTED'])->sum('total_commission');
        $b2bOpen = (float) B2bInvoice::query()->whereIn('status', ['EMITIDA', 'ENVIADA'])
            ->whereColumn('settled_amount', '<', 'subtotal_gross')
            ->selectRaw('coalesce(sum(subtotal_gross - settled_amount), 0) as balance')->value('balance');

        return new JsonResponse(['data' => [
            'date' => $date->toDateString(),
            'patient_flow' => $flow,
            'queue' => $queue,
            'financials_available' => true,
            'revenue' => number_format($revenue, 2, '.', ''),
            'cash_expected' => '0.00',
            'cash_difference' => number_format($cashDifference, 2, '.', ''),
            'commissions' => number_format($commissions, 2, '.', ''),
            'b2b_invoices' => number_format($b2bOpen, 2, '.', ''),
        ]]);
    }

    private function flowBucket(string $status): string
    {
        return match (mb_strtoupper($status)) {
            'WAITING' => 'waiting',
            'IN_SERVICE', 'SERVING', 'CALLED' => 'in_care',
            'CHECKOUT', 'IN_CHECKOUT' => 'checkout',
            'FINISHED', 'DONE', 'COMPLETED' => 'finished',
            default => 'waiting',
        };
    }
}
