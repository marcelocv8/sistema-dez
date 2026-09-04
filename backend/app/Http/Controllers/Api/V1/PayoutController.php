<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\PaymentMethod;
use App\Models\Payout;
use App\Models\PayoutItem;
use App\Models\Professional;
use App\Models\ProfessionalProcedureCommission;
use App\Services\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PayoutController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'professional_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(['OPEN', 'CONFIRMED', 'CONTESTED', 'PAID'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $payouts = Payout::query()->with(['professional:id,name', 'paymentMethod:id,code,name'])
            ->withCount('items')
            ->when($data['professional_id'] ?? null, fn ($query, $id) => $query->where('professional_id', $id))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['from'] ?? null, fn ($query, $from) => $query->where('period_from', '>=', $from))
            ->when($data['to'] ?? null, fn ($query, $to) => $query->where('period_to', '<=', $to))
            ->tap(fn ($query) => $this->scopeToDoctor($request, $query))
            ->orderByDesc('period_from')->orderByDesc('created_at')->limit(50)->get();

        return response()->json(['data' => $payouts]);
    }

    public function show(Request $request, Payout $payout): JsonResponse
    {
        $this->scopeToDoctor($request, Payout::query()->whereKey($payout->id), true);
        $payout->load(['professional:id,name,commission_type,commission_value', 'items.appointment:id,scheduled_at,price_snapshot,payment_status,status', 'items.appointment.serviceOffering.procedure:id,name', 'paymentMethod:id,code,name', 'cashMovement:id,receipt_number']);

        return response()->json(['data' => $payout]);
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'professional_id' => ['required', 'uuid', 'exists:professionals,id'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $user = $request->user();
        $isDoctorSelfService = $user?->role === UserRole::Doctor && $user->hasPermission('payouts.confirm');
        if (! $user?->hasPermission('payouts.manage') && ! $isDoctorSelfService) {
            abort(403, 'Você não possui permissão para acessar este recurso.');
        }

        $payout = DB::transaction(function () use ($request, $data, $isDoctorSelfService): Payout {
            if ($isDoctorSelfService) {
                $ownId = Professional::query()->where('user_id', $request->user()->getAuthIdentifier())->value('id');
                if (! $ownId || $ownId !== $data['professional_id']) {
                    abort(403, 'Médicos geram repasses apenas próprios.');
                }
            }
            $professional = Professional::query()->lockForUpdate()->findOrFail($data['professional_id']);
            if (! $professional->active) {
                throw ValidationException::withMessages(['professional_id' => 'Profissional inativo não gera repasse.']);
            }
            $timezone = (string) config('sistema_dez.timezone', 'America/Fortaleza');
            $start = CarbonImmutable::parse($data['from'], $timezone)->startOfDay();
            $end = CarbonImmutable::parse($data['to'], $timezone)->endOfDay();
            if ($start->diffInDays($end) > 92) {
                throw ValidationException::withMessages(['to' => 'Gere repasses de no máximo 93 dias por vez.']);
            }
            $used = PayoutItem::query()->pluck('appointment_id')->all();
            $appointments = Appointment::query()->with(['serviceOffering.procedure'])
                ->whereHas('serviceOffering', fn ($query) => $query->where('professional_id', $professional->id))
                ->where('status', '!=', 'CANCELLED')
                ->whereIn('payment_status', ['PAID', 'AUTHORIZED'])
                ->whereBetween('scheduled_at', [$start->utc(), $end->utc()])
                ->whereNotIn('id', $used)
                ->orderBy('scheduled_at')->lockForUpdate()->get();
            if ($appointments->isEmpty()) {
                throw ValidationException::withMessages(['period' => 'Nenhuma ficha paga ou autorizada no período para este profissional.']);
            }
            $overrides = ProfessionalProcedureCommission::query()
                ->where('professional_id', $professional->id)->get()->keyBy('procedure_id');

            $payout = Payout::query()->create([
                'professional_id' => $professional->id,
                'period_from' => $data['from'],
                'period_to' => $data['to'],
                'status' => 'OPEN',
                'subtotal_gross' => '0.00',
                'total_commission' => '0.00',
            ]);
            $subtotal = '0.00';
            $total = '0.00';
            foreach ($appointments as $appointment) {
                $gross = (string) $appointment->price_snapshot;
                $override = $overrides->get($appointment->serviceOffering->procedure_id);
                $type = $override?->commission_type ?? $professional->commission_type;
                $value = (string) ($override?->commission_value ?? $professional->commission_value);
                $commission = $type === 'PERCENT'
                    ? bcdiv(bcmul($gross, $value, 4), '100', 2)
                    : bcadd($value, '0', 2);
                $rule = ($override ? 'OVERRIDE_' : 'DEFAULT_').$type;
                PayoutItem::query()->create([
                    'payout_id' => $payout->id,
                    'appointment_id' => $appointment->id,
                    'gross_amount' => $gross,
                    'commission_amount' => $commission,
                    'rule' => $rule,
                ]);
                $subtotal = bcadd($subtotal, $gross, 2);
                $total = bcadd($total, $commission, 2);
            }
            $payout->forceFill(['subtotal_gross' => $subtotal, 'total_commission' => $total])->save();
            $this->audit->record($request, $payout, 'payout.generated', null, $payout->attributesToArray());

            return $payout;
        }, 3);

        return response()->json(['data' => $payout->load(['professional:id,name', 'items'])], 201);
    }

    public function confirm(Request $request, Payout $payout): JsonResponse
    {
        $payout = DB::transaction(function () use ($request, $payout): Payout {
            $payout = Payout::query()->lockForUpdate()->findOrFail($payout->id);
            if (! in_array($payout->status, ['OPEN', 'CONTESTED'], true)) {
                throw ValidationException::withMessages(['payout' => 'Somente repasses abertos ou contestados podem ser confirmados.']);
            }
            $this->guardDoctorOwnership($request, $payout);
            $before = $payout->attributesToArray();
            $payout->forceFill([
                'status' => 'CONFIRMED',
                'confirmed_by' => $request->user()->id,
                'confirmed_at' => now(),
                'contest_note' => null,
            ])->save();
            $this->audit->record($request, $payout, 'payout.confirmed', $before, $payout->attributesToArray());

            return $payout;
        }, 3);

        return response()->json(['data' => $payout->load(['professional:id,name', 'items'])]);
    }

    public function contest(Request $request, Payout $payout): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:1000']]);

        $payout = DB::transaction(function () use ($request, $payout, $data): Payout {
            $payout = Payout::query()->lockForUpdate()->findOrFail($payout->id);
            if (! in_array($payout->status, ['OPEN', 'CONFIRMED'], true)) {
                throw ValidationException::withMessages(['payout' => 'Somente repasses abertos ou confirmados podem ser contestados.']);
            }
            $this->guardDoctorOwnership($request, $payout);
            $before = $payout->attributesToArray();
            $payout->forceFill([
                'status' => 'CONTESTED',
                'contest_note' => $data['note'],
                'confirmed_by' => null,
                'confirmed_at' => null,
            ])->save();
            $this->audit->record($request, $payout, 'payout.contested', $before, $payout->attributesToArray());

            return $payout;
        }, 3);

        return response()->json(['data' => $payout->load(['professional:id,name', 'items'])]);
    }

    public function updateItem(Request $request, PayoutItem $payoutItem): JsonResponse
    {
        $data = $request->validate(['commission_amount' => ['required', 'numeric', 'min:0', 'max:1000000']]);

        $payoutItem = DB::transaction(function () use ($request, $payoutItem, $data): PayoutItem {
            $payoutItem = PayoutItem::query()->lockForUpdate()->findOrFail($payoutItem->id);
            $payout = Payout::query()->lockForUpdate()->findOrFail($payoutItem->payout_id);
            if (! in_array($payout->status, ['OPEN', 'CONTESTED'], true)) {
                throw ValidationException::withMessages(['payout' => 'Somente repasses abertos ou contestados aceitam ajuste.']);
            }
            $before = $payoutItem->attributesToArray();
            $payoutItem->forceFill([
                'commission_amount' => bcadd((string) $data['commission_amount'], '0', 2),
                'rule' => 'MANUAL_ADJUST',
            ])->save();
            $payout->forceFill(['total_commission' => (string) $payout->items()->sum('commission_amount')])->save();
            $this->audit->record($request, $payoutItem, 'payout.item_adjusted', $before, $payoutItem->attributesToArray());

            return $payoutItem;
        }, 3);

        return response()->json(['data' => $payoutItem->load('appointment:id,scheduled_at,price_snapshot')]);
    }

    public function pay(Request $request, Payout $payout): JsonResponse
    {
        $data = $request->validate([
            'payment_method_code' => ['required', Rule::in(['ESPECIE', 'PIX'])],
            'cash_register_id' => ['nullable', 'uuid', 'exists:cash_registers,id'],
            'proof_reference' => ['nullable', 'string', 'max:120'],
        ]);

        $payout = DB::transaction(function () use ($request, $payout, $data): Payout {
            $payout = Payout::query()->lockForUpdate()->findOrFail($payout->id);
            if ($payout->status !== 'CONFIRMED') {
                throw ValidationException::withMessages(['payout' => 'Somente repasses confirmados pelo médico podem ser pagos.']);
            }
            $method = PaymentMethod::query()->where('code', $data['payment_method_code'])->where('active', true)->firstOrFail();
            $before = $payout->attributesToArray();
            $movementId = null;
            if ($method->code === 'ESPECIE') {
                if (empty($data['cash_register_id'])) {
                    throw ValidationException::withMessages(['cash_register_id' => 'Pagamento em espécie exige o caixa aberto.']);
                }
                $register = CashRegister::query()->lockForUpdate()->findOrFail($data['cash_register_id']);
                if ($register->status !== 'ABERTO') {
                    throw ValidationException::withMessages(['cash_register_id' => 'Somente caixas abertos pagam repasse em espécie.']);
                }
                $receipt = $register->last_receipt_number + 1;
                $register->forceFill(['last_receipt_number' => $receipt])->save();
                $movement = CashMovement::query()->create([
                    'cash_register_id' => $register->id,
                    'type' => 'REPASSE',
                    'payment_method_id' => $method->id,
                    'gross_amount' => (string) $payout->total_commission,
                    'fee_amount' => '0.00',
                    'net_amount' => (string) $payout->total_commission,
                    'appointment_id' => null,
                    'receipt_number' => $receipt,
                    'description' => "Repasse {$payout->period_from} a {$payout->period_to}",
                    'proof_reference' => $data['proof_reference'] ?? null,
                    'created_by' => $request->user()->id,
                ]);
                $movementId = $movement->id;
                $this->audit->record($request, $movement, 'cash.repasse_paid', null, $movement->attributesToArray());
            } else {
                if (blank($data['proof_reference'] ?? null) || mb_strlen(trim((string) $data['proof_reference'])) < 3) {
                    throw ValidationException::withMessages(['proof_reference' => 'Pagamento via PIX exige o comprovante (mínimo 3 caracteres).']);
                }
            }
            $payout->forceFill([
                'status' => 'PAID',
                'paid_by' => $request->user()->id,
                'paid_at' => now(),
                'payment_method_id' => $method->id,
                'cash_movement_id' => $movementId,
                'proof_reference' => $data['proof_reference'] ?? null,
            ])->save();
            $this->audit->record($request, $payout, 'payout.paid', $before, $payout->attributesToArray());

            return $payout;
        }, 3);

        return response()->json(['data' => $payout->load(['professional:id,name', 'paymentMethod:id,code,name'])]);
    }

    /** @param Builder<Payout> $query */
    private function scopeToDoctor(Request $request, mixed $query, bool $fail = false): mixed
    {
        $user = $request->user();
        if ($user?->role !== UserRole::Doctor) {
            return $query;
        }
        $professionalId = Professional::query()->where('user_id', $user->getAuthIdentifier())->value('id');
        if (! $professionalId) {
            return $fail ? abort(403, 'Médico sem vínculo com profissional.') : $query->whereRaw('1 = 0');
        }

        return $query->where('professional_id', $professionalId);
    }

    private function guardDoctorOwnership(Request $request, Payout $payout): void
    {
        $user = $request->user();
        if ($user?->role !== UserRole::Doctor) {
            return;
        }
        $owns = Professional::query()->whereKey($payout->professional_id)->where('user_id', $user->getAuthIdentifier())->exists();
        if (! $owns) {
            abort(403, 'Médicos confirmam apenas os próprios repasses.');
        }
    }
}
