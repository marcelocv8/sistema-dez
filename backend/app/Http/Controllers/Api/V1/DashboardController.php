<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\QueueEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $date = today();

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

        return new JsonResponse(['data' => [
            'date' => $date->toDateString(),
            'patient_flow' => $flow,
            'queue' => $queue,
            'financials_available' => false,
            'revenue' => '0.00',
            'cash_expected' => '0.00',
            'cash_difference' => '0.00',
            'commissions' => '0.00',
            'b2b_invoices' => '0.00',
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
