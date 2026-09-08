<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Agreement;
use App\Models\Budget;
use App\Models\BudgetItem;
use App\Models\ProcedurePrice;
use App\Models\Unit;
use App\Services\AuditRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BudgetController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['RASCUNHO', 'ENVIADO', 'ACEITO', 'RECUSADO', 'PERDIDO'])],
            'patient_id' => ['nullable', 'uuid'],
        ]);

        $budgets = Budget::query()->with(['patient:id,name', 'agreement:id,name', 'items'])
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['patient_id'] ?? null, fn ($query, $id) => $query->where('patient_id', $id))
            ->orderByDesc('created_at')->limit(100)->get();

        return response()->json(['data' => $budgets->map(fn (Budget $budget): array => $this->serialize($budget))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'uuid', 'exists:patients,id'],
            'agreement_id' => ['required', 'uuid', 'exists:agreements,id'],
            'unit_id' => ['required', 'uuid', 'exists:units,id'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.procedure_price_id' => ['required', 'uuid', 'exists:procedure_prices,id'],
            'items.*.procedure_id' => ['required', 'uuid', 'exists:procedures,id'],
            'items.*.amount' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
            'prescription' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf,webp'],
        ]);

        $budget = DB::transaction(function () use ($request, $data): Budget {
            $agreementId = $data['agreement_id'];
            abort_unless(Unit::query()->whereKey($data['unit_id'])->where('active', true)->where('operational_enabled', true)->exists(), 422, 'Unidade indisponível.');
            if ($agreementId !== null) {
                $agreement = Agreement::query()->findOrFail($agreementId);
                abort_unless($agreement->active, 422, 'Convênio inativo para orçamento.');
            }
            $budget = Budget::query()->create([
                'patient_id' => $data['patient_id'],
                'agreement_id' => $agreementId,
                'unit_id' => $data['unit_id'],
                'status' => 'RASCUNHO',
                'subtotal_gross' => '0.00',
                'created_by' => $request->user()->id,
            ]);
            foreach ($data['items'] as $item) {
                $price = $this->eligiblePrices($agreementId, $data['unit_id'])->whereKey($item['procedure_price_id'])->first();
                if ($price === null || $price->procedure_id !== $item['procedure_id'] || (int) round((float) $price->amount * 100) !== (int) round((float) $item['amount'] * 100)) {
                    throw ValidationException::withMessages(['items' => 'Preço alterado, vencido ou incompatível com a tabela/unidade. Busque os itens novamente.']);
                }
                $procedure = $price->procedure;
                $budget->items()->create([
                    'procedure_id' => $procedure->id,
                    'procedure_price_id' => $price->id,
                    'procedure_name' => (string) $procedure->name,
                    'amount' => (string) $price->amount,
                    'quantity' => $item['quantity'] ?? 1,
                ]);
            }
            $this->recalculate($budget);

            if ($request->hasFile('prescription')) {
                $this->storePrescription($request, $budget);
            }
            $this->audit->record($request, $budget, 'budget.created', null, $budget->attributesToArray());

            return $budget;
        }, 3);

        return response()->json(['data' => $this->serialize($budget->load(['items', 'patient:id,name']))], 201);
    }

    public function send(Request $request, Budget $budget): JsonResponse
    {
        if ($budget->status !== 'RASCUNHO') {
            throw ValidationException::withMessages(['budget' => 'Somente orçamentos em rascunho podem ser enviados.']);
        }
        if ($budget->items()->count() === 0) {
            throw ValidationException::withMessages(['budget' => 'Orçamento sem itens não pode ser enviado.']);
        }
        $before = $budget->attributesToArray();
        $budget->forceFill(['status' => 'ENVIADO', 'sent_at' => now()])->save();
        $this->audit->record($request, $budget, 'budget.sent', $before, $budget->attributesToArray());

        return response()->json(['data' => $this->serialize($budget->load(['items']))]);
    }

    public function resolve(Request $request, Budget $budget): JsonResponse
    {
        $data = $request->validate([
            'outcome' => ['required', Rule::in(['ACEITO', 'RECUSADO', 'PERDIDO'])],
            'note' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $budget = DB::transaction(function () use ($request, $budget, $data): Budget {
            $locked = Budget::query()->lockForUpdate()->findOrFail($budget->id);
            if ($locked->status !== 'ENVIADO') {
                throw ValidationException::withMessages(['budget' => 'Somente orçamentos enviados podem ser resolvidos (sim ou não obrigatório).']);
            }
            $before = $locked->attributesToArray();
            $locked->forceFill([
                'status' => $data['outcome'],
                'resolution_note' => $data['note'],
                'resolved_by' => $request->user()->id,
                'resolved_at' => now(),
            ])->save();
            $this->audit->record($request, $locked, 'budget.resolved', $before, $locked->attributesToArray());

            return $locked;
        }, 3);

        return response()->json(['data' => $this->serialize($budget->load(['items']))]);
    }

    public function prescription(Request $request, Budget $budget)
    {
        abort_if($budget->prescription_path === null, 404, 'Orçamento sem prescrição anexada.');

        return Storage::disk('budgets')->download($budget->prescription_path, $budget->prescription_original_name ?? 'prescricao');
    }

    public function priceOptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agreement_id' => ['required', 'uuid', 'exists:agreements,id'],
            'unit_id' => ['required', 'uuid', 'exists:units,id'],
            'q' => ['nullable', 'string', 'min:3', 'max:100'],
        ]);
        $prices = $this->eligiblePrices($data['agreement_id'], $data['unit_id'])->with('procedure:id,name,type')
            ->when($data['q'] ?? null, function ($query, $term) {
                $ascii = mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $term) ?: $term);
                $query->whereHas('procedure', fn ($procedure) => $procedure
                    ->whereRaw('lower(coalesce(search_name, name)) like ?', ["%{$ascii}%"])
                    ->orWhere('tuss_code', 'like', "%{$term}%"));
            })
            ->orderBy('procedure_id')->limit(50)->get();

        return response()->json(['data' => $prices->map(fn (ProcedurePrice $price): array => [
            'id' => $price->id,
            'unit_id' => $price->unit_id,
            'procedure_id' => $price->procedure_id,
            'procedure_name' => $price->procedure?->name,
            'type' => $price->procedure?->type?->value,
            'amount' => (string) $price->amount,
        ])->values()->all()]);
    }

    private function eligiblePrices(string $agreementId, string $unitId): Builder
    {
        $today = now(config('sistema_dez.timezone'))->toDateString();

        return ProcedurePrice::query()->where('agreement_id', $agreementId)->where('unit_id', $unitId)->where('status', 'VALID')
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $today))
            ->whereHas('procedure', fn ($q) => $q->where('active', true))
            ->whereHas('agreement', fn ($q) => $q->where('active', true))
            ->whereHas('unit', fn ($q) => $q->where('active', true)->where('operational_enabled', true));
    }

    private function storePrescription(Request $request, Budget $budget): void
    {
        $file = $request->file('prescription');
        $path = $file->store(now()->format('Y/m'), 'budgets');
        $budget->forceFill([
            'prescription_path' => $path,
            'prescription_original_name' => $file->getClientOriginalName(),
        ])->save();
    }

    private function recalculate(Budget $budget): void
    {
        $budget->forceFill(['subtotal_gross' => (string) $budget->items()
            ->selectRaw('sum(amount * quantity) as total')->value('total')])->save();
    }

    /** @return array<string, mixed> */
    private function serialize(Budget $budget): array
    {
        return [
            'id' => $budget->id,
            'patient' => $budget->relationLoaded('patient') ? $budget->patient?->only(['id', 'name']) : null,
            'agreement_id' => $budget->agreement_id,
            'agreement' => $budget->agreement?->only(['id', 'name']),
            'status' => $budget->status,
            'subtotal_gross' => (string) $budget->subtotal_gross,
            'has_prescription' => $budget->prescription_path !== null,
            'sent_at' => $budget->sent_at?->toIso8601String(),
            'resolved_at' => $budget->resolved_at?->toIso8601String(),
            'resolution_note' => $budget->resolution_note,
            'created_at' => $budget->created_at?->toIso8601String(),
            'items' => $budget->relationLoaded('items') ? $budget->items->map(fn (BudgetItem $item): array => [
                'id' => $item->id,
                'procedure_id' => $item->procedure_id,
                'procedure_name' => $item->procedure_name,
                'amount' => (string) $item->amount,
                'quantity' => $item->quantity,
            ])->all() : [],
        ];
    }
}
