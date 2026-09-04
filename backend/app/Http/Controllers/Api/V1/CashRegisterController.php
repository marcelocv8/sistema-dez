<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\PaymentMethod;
use App\Models\Unit;
use App\Services\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CashRegisterController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'unit_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(['ABERTO', 'FECHADO'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $registers = CashRegister::query()->with(['unit:id,name'])
            ->when($data['unit_id'] ?? null, fn ($query, $id) => $query->where('unit_id', $id))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['from'] ?? null, fn ($query, $from) => $query->whereDate('opened_at', '>=', $from))
            ->when($data['to'] ?? null, fn ($query, $to) => $query->whereDate('opened_at', '<=', $to))
            ->orderByDesc('opened_at')->limit(50)->get();

        return response()->json(['data' => $registers]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'unit_id' => ['required', 'uuid', 'exists:units,id'],
            'shift' => ['required', Rule::in(['MANHA', 'TARDE', 'NOITE', 'UNICO'])],
            'float_amount' => ['required', 'numeric', 'min:0', 'max:100000'],
        ]);

        $register = DB::transaction(function () use ($request, $data): CashRegister {
            $unit = Unit::query()->whereKey($data['unit_id'])->where('active', true)->where('operational_enabled', true)->firstOrFail();
            if (CashRegister::query()->where('unit_id', $unit->id)->where('status', 'ABERTO')->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['unit_id' => 'Já existe um caixa aberto para esta unidade. Feche o atual antes de abrir outro.']);
            }
            $register = CashRegister::query()->create([
                'unit_id' => $unit->id,
                'shift' => $data['shift'],
                'float_amount' => $data['float_amount'],
                'status' => 'ABERTO',
                'opened_by' => $request->user()->id,
                'opened_at' => now(),
                'last_receipt_number' => 0,
            ]);
            $this->audit->record($request, $register, 'cash.opened', null, $register->attributesToArray());

            return $register;
        }, 3);

        return response()->json(['data' => $register->load('unit:id,name')], 201);
    }

    public function current(Request $request): JsonResponse
    {
        $data = $request->validate(['unit_id' => ['nullable', 'uuid']]);
        $register = CashRegister::query()->with(['unit:id,name'])
            ->when($data['unit_id'] ?? null, fn ($query, $id) => $query->where('unit_id', $id))
            ->where('status', 'ABERTO')->orderByDesc('opened_at')->first();

        if (! $register) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => [...$register->attributesToArray(),
            'unit' => $register->unit, 'summary' => $this->summary($register)]]);
    }

    public function dailyReport(Request $request): JsonResponse
    {
        $data = $request->validate([
            'unit_id' => ['required', 'uuid', 'exists:units,id'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);
        $timezone = (string) config('sistema_dez.timezone', 'America/Fortaleza');
        $start = CarbonImmutable::parse($data['date'], $timezone)->startOfDay();
        $end = CarbonImmutable::parse($data['date'], $timezone)->endOfDay();

        $registers = CashRegister::query()->with(['unit:id,name'])
            ->where('unit_id', $data['unit_id'])
            ->whereBetween('opened_at', [$start->utc(), $end->utc()])
            ->orderBy('opened_at')->get();

        $rows = [];
        $totalExpected = '0.00';
        $totalCounted = '0.00';
        $totalDifference = '0.00';
        $totalSangria = '0.00';
        $totalVoid = '0.00';
        $gaps = [];
        foreach ($registers as $register) {
            $summary = $this->summary($register);
            $totalExpected = bcadd($totalExpected, $summary['expected_cash'], 2);
            $totalSangria = bcadd($totalSangria, $summary['sangria_total'], 2);
            $totalVoid = bcadd($totalVoid, $summary['void_total'], 2);
            if ($register->status === 'FECHADO') {
                $totalCounted = bcadd($totalCounted, (string) ($register->counted_cash ?? '0.00'), 2);
                $totalDifference = bcadd($totalDifference, (string) ($register->cash_difference ?? '0.00'), 2);
            }
            $numbers = CashMovement::query()->where('cash_register_id', $register->id)
                ->orderBy('receipt_number')->pluck('receipt_number')->all();
            $missing = [];
            if ($numbers !== []) {
                $max = max($numbers);
                for ($i = 1; $i <= $max; $i++) {
                    if (! in_array($i, $numbers, true)) {
                        $missing[] = $i;
                    }
                }
            }
            if ($missing !== []) {
                $gaps[] = ['cash_register_id' => $register->id, 'missing_receipts' => $missing];
            }
            $rows[] = [...$register->attributesToArray(), 'unit' => $register->unit, 'summary' => $summary, 'receipt_gaps' => $missing];
        }

        return response()->json(['data' => [
            'date' => $data['date'],
            'unit_id' => $data['unit_id'],
            'registers' => $rows,
            'totals' => [
                'expected_cash' => $totalExpected,
                'counted_cash' => $totalCounted,
                'cash_difference' => $totalDifference,
                'sangria_total' => $totalSangria,
                'void_total' => $totalVoid,
            ],
            'receipt_gaps' => $gaps,
        ]]);
    }

    public function close(Request $request, CashRegister $cashRegister): JsonResponse
    {
        $data = $request->validate([
            'counted_cash' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $register = DB::transaction(fn (): CashRegister => $this->closeOne($request, $cashRegister->id, (string) $data['counted_cash'], $data['note'] ?? null), 3);

        return response()->json(['data' => $register->load('unit:id,name')]);
    }

    public function closeBatch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.id' => ['required', 'uuid'],
            'items.*.counted_cash' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'items.*.note' => ['nullable', 'string', 'max:1000'],
        ]);

        $closed = [];
        $failed = [];
        foreach ($data['items'] as $item) {
            try {
                $register = DB::transaction(fn (): CashRegister => $this->closeOne($request, $item['id'], (string) $item['counted_cash'], $item['note'] ?? null), 3);
                $closed[] = [
                    'id' => $register->id,
                    'status' => $register->status,
                    'expected_cash' => (string) ($register->totals_snapshot['expected_cash'] ?? '0.00'),
                    'counted_cash' => (string) ($register->counted_cash ?? '0.00'),
                    'cash_difference' => (string) ($register->cash_difference ?? '0.00'),
                ];
            } catch (ValidationException $e) {
                $failed[] = ['id' => $item['id'], 'code' => 422, 'errors' => $e->errors()];
            } catch (ModelNotFoundException $e) {
                $failed[] = ['id' => $item['id'], 'code' => 404, 'message' => 'Caixa não encontrado.'];
            } catch (HttpException $e) {
                $failed[] = ['id' => $item['id'], 'code' => $e->getStatusCode(), 'message' => $e->getMessage()];
            }
        }

        return response()->json(['data' => ['closed' => $closed, 'failed' => $failed]]);
    }

    public function sangria(Request $request, CashRegister $cashRegister): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'description' => ['required', 'string', 'min:3', 'max:1000'],
            'proof_reference' => ['nullable', 'string', 'max:120'],
        ]);

        $movement = DB::transaction(function () use ($request, $cashRegister, $data): CashMovement {
            $register = CashRegister::query()->lockForUpdate()->findOrFail($cashRegister->id);
            if ($register->status !== 'ABERTO') {
                throw ValidationException::withMessages(['cash_register' => 'Somente caixas abertos aceitam sangria.']);
            }
            $method = PaymentMethod::query()->where('code', 'ESPECIE')->where('active', true)->firstOrFail();
            $receipt = $register->last_receipt_number + 1;
            $register->forceFill(['last_receipt_number' => $receipt])->save();
            $movement = CashMovement::query()->create([
                'cash_register_id' => $register->id,
                'type' => 'SANGRIA',
                'payment_method_id' => $method->id,
                'gross_amount' => (string) $data['amount'],
                'fee_amount' => '0.00',
                'net_amount' => (string) $data['amount'],
                'appointment_id' => null,
                'receipt_number' => $receipt,
                'description' => $data['description'],
                'proof_reference' => $data['proof_reference'] ?? null,
                'created_by' => $request->user()->id,
            ]);
            $this->audit->record($request, $movement, 'cash.sangria_created', null, $movement->attributesToArray());

            return $movement;
        }, 3);

        return response()->json(['data' => $movement->load('paymentMethod:id,code,name')], 201);
    }

    private function closeOne(Request $request, string $id, string $countedCash, ?string $note): CashRegister
    {
        $register = CashRegister::query()->lockForUpdate()->findOrFail($id);
        if ($register->status !== 'ABERTO') {
            throw ValidationException::withMessages(['cash_register' => 'Somente caixas abertos podem ser fechados.']);
        }
        $summary = $this->summary($register);
        $difference = bcsub($countedCash, $summary['expected_cash'], 2);
        if (bccomp($difference, '0.00', 2) !== 0) {
            if ($note === null || mb_strlen(trim($note)) < 3) {
                throw ValidationException::withMessages(['note' => 'Quebra de caixa exige justificativa (mínimo 3 caracteres).']);
            }
            if (! $request->user()?->hasPermission('discounts.validate')) {
                abort(403, 'Quebra de caixa exige supervisor (discounts.validate).');
            }
        }
        $before = $register->attributesToArray();
        $register->forceFill([
            'status' => 'FECHADO',
            'closed_by' => $request->user()->id,
            'closed_at' => now(),
            'counted_cash' => $countedCash,
            'cash_difference' => $difference,
            'close_note' => $note,
            'shortage_approved_by' => bccomp($difference, '0.00', 2) !== 0 ? $request->user()->id : null,
            'totals_snapshot' => [...$summary,
                'counted_cash' => (string) bcadd($countedCash, '0', 2),
                'cash_difference' => $difference,
                'close_note' => $note,
            ],
        ])->save();
        $this->audit->record($request, $register, 'cash.closed', $before, $register->attributesToArray());

        return $register;
    }

    /** @return array<string, mixed> */
    private function summary(CashRegister $register): array
    {
        $rows = CashMovement::query()->with('paymentMethod:id,code,name')
            ->where('cash_register_id', $register->id)->get();
        $byMethod = [];
        $especieIn = '0.00';
        $sangriaTotal = '0.00';
        $voidTotal = '0.00';
        foreach ($rows as $movement) {
            if ($movement->type === 'SANGRIA') {
                $sangriaTotal = bcadd($sangriaTotal, (string) $movement->net_amount, 2);

                continue;
            }
            if ($movement->type === 'ESTORNO') {
                $voidTotal = bcadd($voidTotal, (string) $movement->net_amount, 2);
                if ($movement->paymentMethod->code === 'ESPECIE') {
                    $especieIn = bcsub($especieIn, (string) $movement->net_amount, 2);
                }

                continue;
            }
            $code = $movement->paymentMethod->code;
            $byMethod[$code] ??= ['method' => $code, 'name' => $movement->paymentMethod->name, 'count' => 0, 'gross' => '0.00', 'fee' => '0.00', 'net' => '0.00'];
            $byMethod[$code]['count']++;
            $byMethod[$code]['gross'] = bcadd($byMethod[$code]['gross'], (string) $movement->gross_amount, 2);
            $byMethod[$code]['fee'] = bcadd($byMethod[$code]['fee'], (string) $movement->fee_amount, 2);
            $byMethod[$code]['net'] = bcadd($byMethod[$code]['net'], (string) $movement->net_amount, 2);
            if ($code === 'ESPECIE') {
                $especieIn = bcadd($especieIn, (string) $movement->net_amount, 2);
            }
        }
        $expected = bcsub(bcadd((string) $register->float_amount, $especieIn, 2), $sangriaTotal, 2);

        return [
            'receipt_count' => $rows->count(),
            'by_method' => array_values($byMethod),
            'float_amount' => (string) $register->float_amount,
            'especie_in' => $especieIn,
            'sangria_total' => $sangriaTotal,
            'void_total' => $voidTotal,
            'expected_cash' => $expected,
        ];
    }
}
