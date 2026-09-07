<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Agreement;
use App\Models\Appointment;
use App\Models\B2bInvoice;
use App\Services\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class B2bInvoiceController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agreement_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(['EMITIDA', 'ENVIADA', 'PAGA', 'VENCIDA'])],
            'competence' => ['nullable', 'date_format:Y-m'],
        ]);
        $today = CarbonImmutable::today((string) config('sistema_dez.timezone'))->toDateString();

        $invoices = B2bInvoice::query()->with(['agreement:id,name', 'items'])
            ->when($data['agreement_id'] ?? null, fn ($query, $id) => $query->where('agreement_id', $id))
            ->when($data['competence'] ?? null, fn ($query, $competence) => $query->where('competence', "{$competence}-01"))
            ->when(($data['status'] ?? null) === 'VENCIDA', fn ($query) => $query
                ->where('status', '!=', 'PAGA')
                ->where('due_at', '<', $today)
                ->whereColumn('settled_amount', '<', 'subtotal_gross'))
            ->when(isset($data['status']) && $data['status'] !== 'VENCIDA', fn ($query) => $query->where('status', $data['status']))
            ->orderByDesc('competence')->orderBy('agreement_id')->limit(100)->get();

        return response()->json(['data' => $invoices->map(fn (B2bInvoice $invoice): array => $this->serialize($invoice))->all()]);
    }

    public function show(B2bInvoice $b2bInvoice): JsonResponse
    {
        return response()->json(['data' => $this->serialize($b2bInvoice->load(['agreement:id,name', 'items.appointment:id,scheduled_at']))]);
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agreement_id' => ['required', 'uuid', 'exists:agreements,id'],
            'competence' => ['required', 'date_format:Y-m'],
        ]);

        $invoice = DB::transaction(function () use ($request, $data): B2bInvoice {
            $agreement = Agreement::query()->lockForUpdate()->findOrFail($data['agreement_id']);
            if (! $agreement->active || $agreement->is_default) {
                throw ValidationException::withMessages(['agreement_id' => 'Fatura B2B exige convênio empresarial ativo (não o Particular padrão).']);
            }
            $timezone = (string) config('sistema_dez.timezone');
            $start = CarbonImmutable::createFromFormat('Y-m-d', "{$data['competence']}-01", $timezone)->startOfDay();
            $end = $start->addMonth()->subSecond();

            $candidates = Appointment::query()
                ->where('payment_status', 'AUTHORIZED')
                ->whereIn('status', ['CONFIRMED', 'CHECKED_IN', 'DONE'])
                ->whereBetween('scheduled_at', [$start->utc(), $end->utc()])
                ->whereHas('serviceOffering', fn ($query) => $query->where('agreement_id', $agreement->id))
                ->whereNotIn('id', fn ($query) => $query->select('appointment_id')->from('b2b_invoice_items'))
                ->with(['patient:id,name', 'serviceOffering.procedure:id,name'])
                ->lockForUpdate()->get();

            $invoice = B2bInvoice::query()->where('agreement_id', $agreement->id)
                ->where('competence', $start->toDateString())->lockForUpdate()->first();
            if (! $invoice && $candidates->isEmpty()) {
                throw ValidationException::withMessages(['competence' => 'Nenhum atendimento B2B autorizado nesta competência para esta empresa.']);
            }
            if (! $invoice) {
                $issuedAt = now();
                $invoice = B2bInvoice::query()->create([
                    'agreement_id' => $agreement->id,
                    'competence' => $start->toDateString(),
                    'status' => 'EMITIDA',
                    'subtotal_gross' => '0.00',
                    'settled_amount' => '0.00',
                    'issued_at' => $issuedAt,
                    'due_at' => CarbonImmutable::parse($issuedAt)->setTimezone($timezone)->addDays(10)->toDateString(),
                    'created_by' => $request->user()->id,
                ]);
            }
            if ($invoice->status === 'PAGA') {
                throw ValidationException::withMessages(['competence' => 'Esta fatura já foi quitada; não é possível incluir novos atendimentos.']);
            }
            foreach ($candidates as $appointment) {
                $invoice->items()->create([
                    'appointment_id' => $appointment->id,
                    'scheduled_at' => $appointment->scheduled_at,
                    'patient_name' => (string) $appointment->patient?->name,
                    'procedure_name' => (string) $appointment->serviceOffering->procedure?->name,
                    'gross_amount' => (string) $appointment->price_snapshot,
                ]);
            }
            $invoice->forceFill(['subtotal_gross' => (string) $invoice->items()->sum('gross_amount')])->save();
            $this->audit->record($request, $invoice, 'b2b.generated', null, [...$invoice->attributesToArray(), 'added_items' => $candidates->count()]);

            return $invoice;
        }, 3);

        return response()->json(['data' => $this->serialize($invoice->load(['agreement:id,name', 'items']))], 201);
    }

    public function send(Request $request, B2bInvoice $b2bInvoice): JsonResponse
    {
        if ($b2bInvoice->status !== 'EMITIDA') {
            throw ValidationException::withMessages(['invoice' => 'Somente faturas emitidas podem ser encaminhadas à empresa.']);
        }
        $before = $b2bInvoice->attributesToArray();
        $b2bInvoice->forceFill(['status' => 'ENVIADA', 'sent_at' => now()])->save();
        $this->audit->record($request, $b2bInvoice, 'b2b.sent', $before, $b2bInvoice->attributesToArray());

        return response()->json(['data' => $this->serialize($b2bInvoice->load(['agreement:id,name', 'items']))]);
    }

    public function settle(Request $request, B2bInvoice $b2bInvoice): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:10000000'],
            'proof_reference' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $invoice = DB::transaction(function () use ($request, $b2bInvoice, $data): B2bInvoice {
            $invoice = B2bInvoice::query()->withCount('items')->lockForUpdate()->findOrFail($b2bInvoice->id);
            if ($invoice->items_count === 0) {
                throw ValidationException::withMessages(['invoice' => 'Fatura sem itens não pode ser baixada.']);
            }
            $remaining = $invoice->remainingAmount();
            if (bccomp((string) $data['amount'], $remaining, 2) > 0) {
                throw ValidationException::withMessages(['amount' => "Valor acima do restante em aberto (R$ {$remaining})."]);
            }
            $before = $invoice->attributesToArray();
            $settled = bcadd((string) $invoice->settled_amount, (string) $data['amount'], 2);
            $invoice->forceFill([
                'settled_amount' => $settled,
                'proof_reference' => $data['proof_reference'],
                'status' => bccomp($settled, (string) $invoice->subtotal_gross, 2) >= 0 ? 'PAGA' : $invoice->status,
                'settled_at' => bccomp($settled, (string) $invoice->subtotal_gross, 2) >= 0 ? now() : $invoice->settled_at,
            ])->save();
            $this->audit->record($request, $invoice, 'b2b.settled', $before, [...$invoice->attributesToArray(), 'settled_now' => (string) $data['amount']]);

            return $invoice;
        }, 3);

        return response()->json(['data' => $this->serialize($invoice->load(['agreement:id,name', 'items']))]);
    }

    /** @return array<string, mixed> */
    private function serialize(B2bInvoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'agreement' => $invoice->relationLoaded('agreement') ? ['id' => $invoice->agreement->id, 'name' => $invoice->agreement->name] : ['id' => $invoice->agreement_id, 'name' => null],
            'competence' => $invoice->competence instanceof CarbonImmutable ? $invoice->competence->toDateString() : (string) $invoice->competence,
            'status' => $invoice->status,
            'computed_status' => $invoice->computedStatus(),
            'overdue' => $invoice->isOverdue(),
            'subtotal_gross' => (string) $invoice->subtotal_gross,
            'settled_amount' => (string) $invoice->settled_amount,
            'remaining_amount' => $invoice->remainingAmount(),
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'due_at' => $invoice->due_at instanceof CarbonImmutable ? $invoice->due_at->toDateString() : (string) $invoice->due_at,
            'sent_at' => $invoice->sent_at?->toIso8601String(),
            'settled_at' => $invoice->settled_at?->toIso8601String(),
            'proof_reference' => $invoice->proof_reference,
            'items_count' => $invoice->relationLoaded('items') ? $invoice->items->count() : $invoice->items()->count(),
            'items' => $invoice->relationLoaded('items') ? $invoice->items->map(fn ($item): array => [
                'id' => $item->id,
                'appointment_id' => $item->appointment_id,
                'scheduled_at' => $item->scheduled_at?->toIso8601String(),
                'patient_name' => $item->patient_name,
                'procedure_name' => $item->procedure_name,
                'gross_amount' => (string) $item->gross_amount,
            ])->all() : [],
        ];
    }
}
