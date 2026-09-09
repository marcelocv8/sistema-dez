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
use App\Models\Patient;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API de leitura para agentes Hermes (§6.14) — somente leitura, escopado,
 * autenticado por token de agente e auditado (agent_requests).
 */
class HermesController extends Controller
{
    public function agenda(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'unit_id' => ['nullable', 'uuid', 'exists:units,id'],
        ]);

        $timezone = config('sistema_dez.timezone');
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'], $timezone)->startOfDay() : CarbonImmutable::today($timezone)->startOfDay();
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'], $timezone)->endOfDay() : $from->addDays(7)->endOfDay();
        abort_unless($from->diffInDays($to) <= 60, 422, 'Intervalo máximo de 60 dias.');

        $appointments = Appointment::query()
            ->with(['patient:id,name,cpf_last_four', 'serviceOffering.procedure:id,name', 'serviceOffering.professional:id,name', 'serviceOffering.unit:id,name'])
            ->whereBetween('scheduled_at', [$from->utc(), $to->utc()])
            ->when($data['unit_id'] ?? null, fn ($query, $id) => $query->whereHas('serviceOffering', fn ($offering) => $offering->where('unit_id', $id)))
            ->orderBy('scheduled_at')
            ->limit(2000)
            ->get()
            ->map(fn (Appointment $appointment): array => [
                'id' => $appointment->id,
                'scheduled_at' => $appointment->scheduled_at?->toIso8601String(),
                'status' => $appointment->status,
                'payment_status' => $appointment->payment_status,
                'patient' => $appointment->patient?->only(['id', 'name', 'cpf_last_four']),
                'procedure' => $appointment->serviceOffering?->procedure?->only(['id', 'name']),
                'professional' => $appointment->serviceOffering?->professional?->only(['id', 'name']),
                'unit' => $appointment->serviceOffering?->unit?->only(['id', 'name']),
            ])->all();

        return response()->json(['data' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'appointments' => $appointments]]);
    }

    public function patients(Request $request): JsonResponse
    {
        $data = $request->validate(['cpf' => ['required', 'digits:11']]);

        $hash = hash_hmac('sha256', $data['cpf'], (string) config('sistema_dez.identity_pepper'));
        $patient = Patient::query()->where('cpf_hash', $hash)->first();

        if ($patient === null) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => [
            'id' => $patient->id,
            'name' => $patient->name,
            'birth_date' => $patient->birth_date?->toDateString(),
            'cpf_last_four' => $patient->cpf_last_four,
            'phone' => $patient->phone,
        ]]);
    }

    public function production(Request $request): JsonResponse
    {
        $data = $this->validateRange($request);
        [$from, $to] = $data;

        $paid = Appointment::query()->whereBetween('paid_at', [$from->utc(), $to->utc()])->where('payment_status', 'PAID');
        $revenue = (float) (clone $paid)->sum('price_snapshot');
        $count = (clone $paid)->count();

        $production = Appointment::query()
            ->whereBetween('scheduled_at', [$from->utc(), $to->utc()])
            ->where('status', 'DONE')
            ->join('service_offerings', 'service_offerings.id', '=', 'appointments.service_offering_id')
            ->join('professionals', 'professionals.id', '=', 'service_offerings.professional_id')
            ->selectRaw('professionals.id, professionals.name, count(*) as appointments, sum(price_snapshot) as revenue')
            ->groupBy('professionals.id', 'professionals.name')
            ->get()
            ->map(fn ($row): array => ['doctor_id' => $row->id, 'doctor' => $row->name, 'appointments' => (int) $row->appointments, 'revenue' => (float) $row->revenue])
            ->sortByDesc('revenue')->values()->all();

        return response()->json(['data' => [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'revenue' => number_format($revenue, 2, '.', ''),
            'paid_appointments' => $count,
            'ticket_avg' => $count > 0 ? number_format($revenue / $count, 2, '.', '') : '0.00',
            'production_by_doctor' => $production,
        ]]);
    }

    public function cashSummary(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date']]);
        $timezone = config('sistema_dez.timezone');
        $date = isset($data['date']) ? CarbonImmutable::parse($data['date'], $timezone) : CarbonImmutable::today($timezone);
        $range = [$date->startOfDay()->utc(), $date->endOfDay()->utc()];

        $registers = CashRegister::query()->where('status', 'FECHADO')->whereBetween('closed_at', $range)->get(['id', 'status', 'cash_difference', 'closed_at']);
        $movements = CashMovement::query()->whereBetween('created_at', $range);
        $received = (clone $movements)->whereIn('type', ['RECEBIMENTO', 'MENSALIDADE'])->sum('net_amount');
        $refunded = (clone $movements)->where('type', 'ESTORNO')->sum('net_amount');
        $revenue = bcsub((string) $received, (string) $refunded, 2);

        return response()->json(['data' => [
            'date' => $date->toDateString(),
            'registers_closed' => $registers->count(),
            'cash_difference' => number_format((float) $registers->sum('cash_difference'), 2, '.', ''),
            'revenue' => number_format($revenue, 2, '.', ''),
        ]]);
    }

    public function b2bInvoices(): JsonResponse
    {
        $invoices = B2bInvoice::query()
            ->whereIn('status', ['EMITIDA', 'ENVIADA'])
            ->whereColumn('settled_amount', '<', 'subtotal_gross')
            ->with('agreement:id,name')
            ->orderBy('due_at')
            ->limit(500)
            ->get()
            ->map(fn (B2bInvoice $invoice): array => [
                'id' => $invoice->id,
                'agreement' => $invoice->agreement?->only(['id', 'name']),
                'competence' => $invoice->competence->toDateString(),
                'status' => $invoice->status,
                'due_at' => $invoice->due_at,
                'balance' => number_format((float) $invoice->subtotal_gross - (float) $invoice->settled_amount, 2, '.', ''),
            ])->all();

        return response()->json(['data' => ['invoices' => $invoices]]);
    }

    public function asosDue(Request $request): JsonResponse
    {
        $data = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:365'], 'agreement_id' => ['nullable', 'uuid', 'exists:agreements,id']]);

        $days = (int) ($data['days'] ?? 30);
        $limit = CarbonImmutable::today(config('sistema_dez.timezone'))->addDays($days);

        $asos = MtAso::query()
            ->where('type', AsoType::Periodico)
            ->whereNot('status', AsoStatus::Cancelado->value)
            ->whereDate('expires_at', '<=', $limit)
            ->when($data['agreement_id'] ?? null, fn ($query, $id) => $query->where('agreement_id', $id))
            ->with(['patient:id,name', 'agreement:id,name', 'sector:id,name', 'function:id,name'])
            ->orderBy('expires_at')
            ->limit(1000)
            ->get()
            ->map(fn (MtAso $aso): array => [
                'id' => $aso->id,
                'agreement' => $aso->agreement?->only(['id', 'name']),
                'patient' => $aso->patient?->only(['id', 'name']),
                'sector' => $aso->sector?->only(['id', 'name']),
                'function' => $aso->function?->only(['id', 'name']),
                'status' => $aso->status->value,
                'expires_at' => $aso->expires_at?->toDateString(),
            ])->all();

        return response()->json(['data' => ['days' => $days, 'asos' => $asos]]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function validateRange(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $timezone = config('sistema_dez.timezone');
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'], $timezone)->startOfDay() : CarbonImmutable::today($timezone)->subDays(29)->startOfDay();
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'], $timezone)->endOfDay() : CarbonImmutable::today($timezone)->endOfDay();
        abort_unless($from->diffInDays($to) <= 366, 422, 'Intervalo máximo de 366 dias.');

        return [$from, $to];
    }
}
