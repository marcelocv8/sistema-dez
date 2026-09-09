<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AsoStatus;
use App\Enums\AsoType;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\B2bInvoice;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\MtAso;
use App\Models\Payout;
use App\Models\QueueEntry;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'unit_id' => ['nullable', 'uuid', 'exists:units,id'],
            'doctor_id' => ['nullable', 'uuid', 'exists:professionals,id'],
            'agreement_id' => ['nullable', 'uuid', 'exists:agreements,id'],
        ]);

        $timezone = config('sistema_dez.timezone');
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'], $timezone)->endOfDay() : CarbonImmutable::today($timezone)->endOfDay();
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'], $timezone)->startOfDay() : $to->subDays(29)->startOfDay();

        $appointmentQuery = Appointment::query()
            ->when($data['unit_id'] ?? null, fn ($query, $id) => $query->whereHas('serviceOffering', fn ($offering) => $offering->where('unit_id', $id)))
            ->when($data['doctor_id'] ?? null, fn ($query, $id) => $query->whereHas('serviceOffering', fn ($offering) => $offering->where('professional_id', $id)))
            ->when($data['agreement_id'] ?? null, fn ($query, $id) => $query->whereHas('serviceOffering', fn ($offering) => $offering->where('agreement_id', $id)));

        $scheduled = (clone $appointmentQuery)->whereBetween('scheduled_at', [$from->utc(), $to->utc()])->count();
        $done = (clone $appointmentQuery)->whereBetween('scheduled_at', [$from->utc(), $to->utc()])->where('status', 'DONE')->count();
        $cancelled = (clone $appointmentQuery)->whereBetween('scheduled_at', [$from->utc(), $to->utc()])->where('status', 'CANCELLED')->count();
        $noShow = (clone $appointmentQuery)->whereBetween('scheduled_at', [$from->utc(), $to->utc()])->where('status', 'NO_SHOW')->count();
        $attended = $done + $noShow;

        $paidBase = (clone $appointmentQuery)
            ->whereBetween('paid_at', [$from->utc(), $to->utc()])
            ->where('payment_status', 'PAID');

        $revenue = (float) (clone $paidBase)->sum('price_snapshot');
        $paidCount = (clone $paidBase)->count();
        $ticketAvg = $paidCount > 0 ? round($revenue / $paidCount, 2) : 0.0;

        $paymentMix = (clone $paidBase)->join('payment_methods', 'payment_methods.id', '=', 'appointments.payment_method_id')
            ->selectRaw('payment_methods.name, count(*) as count, sum(price_snapshot) as total')
            ->groupBy('payment_methods.name')
            ->get()
            ->map(fn ($row): array => ['method' => $row->name, 'total' => (float) $row->total, 'count' => (int) $row->count])
            ->all();

        $production = (clone $appointmentQuery)
            ->whereBetween('scheduled_at', [$from->utc(), $to->utc()])
            ->where('status', 'DONE')
            ->join('service_offerings', 'service_offerings.id', '=', 'appointments.service_offering_id')
            ->join('professionals', 'professionals.id', '=', 'service_offerings.professional_id')
            ->selectRaw('professionals.id, professionals.name, count(*) as appointments, sum(price_snapshot) as revenue')
            ->groupBy('professionals.id', 'professionals.name')
            ->get()
            ->map(fn ($row): array => [
                'doctor_id' => $row->id,
                'doctor' => $row->name,
                'appointments' => (int) $row->appointments,
                'revenue' => (float) $row->revenue,
            ])
            ->sortByDesc('revenue')->values()->all();

        $revenueByAgreement = (clone $paidBase)
            ->join('service_offerings', 'service_offerings.id', '=', 'appointments.service_offering_id')
            ->join('agreements', 'agreements.id', '=', 'service_offerings.agreement_id')
            ->selectRaw('agreements.id, agreements.name, count(*) as count, sum(price_snapshot) as total')
            ->groupBy('agreements.id', 'agreements.name')
            ->get()
            ->map(fn ($row): array => [
                'agreement_id' => $row->id,
                'agreement' => $row->name,
                'total' => (float) $row->total,
                'count' => (int) $row->count,
            ])
            ->sortByDesc('total')->values()->all();

        $closedRegisters = CashRegister::query()->where('status', 'FECHADO')
            ->whereBetween('closed_at', [$from->utc(), $to->utc()])
            ->when($data['unit_id'] ?? null, fn ($query, $id) => $query->where('unit_id', $id))
            ->get(['id', 'cash_difference', 'closed_at']);
        $cashBreak = (float) $closedRegisters->sum('cash_difference');

        $movementQuery = CashMovement::query()->whereBetween('created_at', [$from->utc(), $to->utc()])
            ->when($data['unit_id'] ?? null, fn ($query, $id) => $query->whereHas('register', fn ($register) => $register->where('unit_id', $id)));
        $sangrias = (float) (clone $movementQuery)->where('type', 'SANGRIA')->sum('gross_amount');
        $payoutsPaid = (float) (clone $movementQuery)->where('type', 'REPASSE')->sum('gross_amount');

        $invoiceQuery = B2bInvoice::query()
            ->whereIn('status', ['EMITIDA', 'ENVIADA'])
            ->whereColumn('settled_amount', '<', 'subtotal_gross')
            ->when($data['agreement_id'] ?? null, fn ($query, $id) => $query->where('agreement_id', $id));
        $openInvoices = $invoiceQuery->get(['id', 'due_at', 'subtotal_gross', 'settled_amount']);
        $today = CarbonImmutable::today($timezone);
        $aging = ['a_vencer' => 0.0, 'vencida_30' => 0.0, 'vencida_60' => 0.0, 'vencida_90' => 0.0, 'vencida_90_mais' => 0.0];
        foreach ($openInvoices as $invoice) {
            $open = (float) $invoice->subtotal_gross - (float) $invoice->settled_amount;
            $due = CarbonImmutable::parse($invoice->due_at, $timezone);
            $bucket = match (true) {
                $due->greaterThanOrEqualTo($today) => 'a_vencer',
                $due->diffInDays($today) <= 30 => 'vencida_30',
                $due->diffInDays($today) <= 60 => 'vencida_60',
                $due->diffInDays($today) <= 90 => 'vencida_90',
                default => 'vencida_90_mais',
            };
            $aging[$bucket] = round($aging[$bucket] + $open, 2);
        }

        $queueQuery = QueueEntry::query()
            ->whereIn('status', ['FINISHED', 'DONE', 'COMPLETED'])
            ->whereBetween('service_date', [$from->toDateString(), $to->toDateString()])
            ->when($data['unit_id'] ?? null, fn ($query, $id) => $query->where('unit_id', $id));
        $avgCareMinutes = (int) round($queueQuery->get(['checked_in_at', 'updated_at'])
            ->avg(fn (QueueEntry $entry): float => max(0, $entry->checked_in_at->diffInMinutes($entry->updated_at))));

        $dueLimit = CarbonImmutable::today($timezone)->addDays((int) config('sistema_dez.mt_due_warning_days'));
        $asosDue = MtAso::query()->where('type', AsoType::Periodico)
            ->whereNot('status', AsoStatus::Cancelado->value)
            ->whereDate('expires_at', '<=', $dueLimit)
            ->when($data['agreement_id'] ?? null, fn ($query, $id) => $query->where('agreement_id', $id))
            ->count();

        $payoutsOpen = Payout::query()->whereIn('status', ['OPEN', 'CONFIRMED', 'CONTESTED'])
            ->sum('total_commission');

        return response()->json(['data' => [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'appointments' => [
                'scheduled' => $scheduled,
                'done' => $done,
                'cancelled' => $cancelled,
                'no_show' => $noShow,
                'no_show_rate' => $attended > 0 ? round($noShow / $attended * 100, 1) : 0.0,
            ],
            'revenue' => number_format($revenue, 2, '.', ''),
            'ticket_avg' => number_format($ticketAvg, 2, '.', ''),
            'payment_mix' => $paymentMix,
            'production_by_doctor' => $production,
            'revenue_by_agreement' => $revenueByAgreement,
            'cash' => [
                'break_total' => number_format($cashBreak, 2, '.', ''),
                'sangrias' => number_format($sangrias, 2, '.', ''),
                'payouts_paid' => number_format($payoutsPaid, 2, '.', ''),
                'payouts_open' => number_format((float) $payoutsOpen, 2, '.', ''),
            ],
            'b2b_open' => [
                'count' => $openInvoices->count(),
                'aging' => $aging,
            ],
            'queue' => ['avg_care_minutes' => $avgCareMinutes],
            'asos_due' => $asosDue,
        ]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:production,revenue,b2b_open,asos_due'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'agreement_id' => ['nullable', 'uuid', 'exists:agreements,id'],
            'unit_id' => ['nullable', 'uuid', 'exists:units,id'],
            'doctor_id' => ['nullable', 'uuid', 'exists:professionals,id'],
        ]);

        $timezone = config('sistema_dez.timezone');
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'], $timezone)->endOfDay() : CarbonImmutable::today($timezone)->endOfDay();
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'], $timezone)->startOfDay() : $to->subDays(29)->startOfDay();

        $rows = match ($data['type']) {
            'production' => Appointment::query()
                ->when($data['agreement_id'] ?? null, fn ($query, $id) => $query->whereHas('serviceOffering', fn ($offering) => $offering->where('agreement_id', $id)))
                ->when($data['unit_id'] ?? null, fn ($query, $id) => $query->whereHas('serviceOffering', fn ($offering) => $offering->where('unit_id', $id)))
                ->when($data['doctor_id'] ?? null, fn ($query, $id) => $query->whereHas('serviceOffering', fn ($offering) => $offering->where('professional_id', $id)))
                ->whereBetween('scheduled_at', [$from->utc(), $to->utc()])
                ->where('status', 'DONE')
                ->join('service_offerings', 'service_offerings.id', '=', 'appointments.service_offering_id')
                ->join('professionals', 'professionals.id', '=', 'service_offerings.professional_id')
                ->selectRaw('professionals.id, professionals.name, count(*) as appointments, sum(price_snapshot) as revenue')
                ->groupBy('professionals.id', 'professionals.name')
                ->get()
                ->map(fn ($row): array => [$row->name, $row->appointments, $row->revenue]),
            'revenue' => Appointment::query()
                ->when($data['agreement_id'] ?? null, fn ($query, $id) => $query->whereHas('serviceOffering', fn ($offering) => $offering->where('agreement_id', $id)))
                ->when($data['unit_id'] ?? null, fn ($query, $id) => $query->whereHas('serviceOffering', fn ($offering) => $offering->where('unit_id', $id)))
                ->when($data['doctor_id'] ?? null, fn ($query, $id) => $query->whereHas('serviceOffering', fn ($offering) => $offering->where('professional_id', $id)))
                ->whereBetween('paid_at', [$from->utc(), $to->utc()])
                ->where('payment_status', 'PAID')
                ->join('service_offerings', 'service_offerings.id', '=', 'appointments.service_offering_id')
                ->join('agreements', 'agreements.id', '=', 'service_offerings.agreement_id')
                ->selectRaw('agreements.id, agreements.name, count(*) as count, sum(price_snapshot) as total')
                ->groupBy('agreements.id', 'agreements.name')
                ->get()
                ->map(fn ($row): array => [$row->name, $row->count, $row->total]),
            'b2b_open' => B2bInvoice::query()
                ->whereIn('status', ['EMITIDA', 'ENVIADA'])
                ->whereColumn('settled_amount', '<', 'subtotal_gross')
                ->when($data['agreement_id'] ?? null, fn ($query, $id) => $query->where('agreement_id', $id))
                ->with('agreement:id,name')
                ->get()
                ->map(fn (B2bInvoice $invoice): array => [
                    $invoice->agreement?->name, $invoice->competence->toDateString(), $invoice->status,
                    $invoice->due_at, (float) $invoice->subtotal_gross - (float) $invoice->settled_amount,
                ]),
            'asos_due' => MtAso::query()
                ->when($data['agreement_id'] ?? null, fn ($query, $id) => $query->where('agreement_id', $id))
                ->where('type', AsoType::Periodico)
                ->whereNot('status', AsoStatus::Cancelado->value)
                ->whereDate('expires_at', '<=', CarbonImmutable::today($timezone)->addDays((int) config('sistema_dez.mt_due_warning_days')))
                ->with(['patient:id,name', 'agreement:id,name', 'sector:id,name', 'function:id,name'])
                ->get()
                ->map(fn (MtAso $aso): array => [
                    $aso->agreement?->name, $aso->patient?->name, $aso->sector?->name, $aso->function?->name,
                    $aso->type->label(), $aso->status->value, $aso->expires_at?->toDateString(),
                ]),
        };

        $headers = match ($data['type']) {
            'production' => ['medico', 'atendimentos', 'receita'],
            'revenue' => ['convenio', 'pagamentos', 'total'],
            'b2b_open' => ['empresa', 'competencia', 'status', 'vencimento', 'saldo'],
            default => ['empresa', 'funcionario', 'setor', 'funcao', 'tipo', 'status', 'vencimento'],
        };

        $filename = 'dez-'.$data['type'].'-'.now()->format('Ymd-Hi').'.csv';

        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers, escape: '');
            foreach ($rows as $row) {
                fputcsv($out, array_map(static fn ($value): string => (string) $value, $row), escape: '');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
