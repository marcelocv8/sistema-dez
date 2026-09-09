<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\B2bInvoice;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\PaymentMethod;
use App\Models\Payout;
use App\Models\PayoutItem;
use App\Models\QueueEntry;
use App\Services\AuditRecorder;
use App\Services\LoyaltyEligibility;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentPaymentController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit, private readonly LoyaltyEligibility $loyalty) {}

    public function checkout(Request $request, Appointment $appointment): JsonResponse
    {
        $data = $request->validate(['payment_method_code' => ['required', 'string', 'max:20']]);

        $result = DB::transaction(function () use ($request, $appointment, $data): array {
            $appointment = Appointment::query()->with(['patient', 'serviceOffering.unit', 'serviceOffering.procedure'])->lockForUpdate()->findOrFail($appointment->id);
            if (! in_array($appointment->status, ['CONFIRMED', 'CHECKED_IN'], true)) {
                throw ValidationException::withMessages(['appointment' => 'Somente fichas confirmadas ou com check-in podem ser cobradas.']);
            }
            if ($appointment->payment_status !== 'PENDING') {
                throw ValidationException::withMessages(['appointment' => 'Esta ficha já foi paga ou autorizada.']);
            }
            $offering = $appointment->serviceOffering;
            if (! $offering->active || ! $offering->unit->active || ! $offering->unit->operational_enabled) {
                throw ValidationException::withMessages(['appointment' => 'A oferta não está habilitada para a operação.']);
            }
            $loyaltyPlan = $this->loyalty->planForAgreement($offering->agreement_id);
            if ($loyaltyPlan && $appointment->patient) {
                $eligibility = $this->loyalty->check($loyaltyPlan, $appointment->patient);
                if (! $eligibility['eligible']) {
                    throw ValidationException::withMessages(['appointment' => (string) $eligibility['reason']]);
                }
            }
            $register = CashRegister::query()->where('unit_id', $offering->unit_id)->where('status', 'ABERTO')->lockForUpdate()->first();
            if (! $register) {
                throw ValidationException::withMessages(['cash_register' => 'Nenhum caixa aberto para esta unidade. Abra o caixa antes de cobrar.']);
            }
            $method = PaymentMethod::query()->where('code', mb_strtoupper($data['payment_method_code']))->where('active', true)->first();
            if (! $method) {
                throw ValidationException::withMessages(['payment_method_code' => 'Forma de pagamento inválida ou inativa.']);
            }

            $gross = (string) $appointment->price_snapshot;
            $fee = bcdiv(bcmul($gross, (string) $method->extra_percent, 4), '100', 2);
            $net = bcadd($gross, $fee, 2);
            $receipt = $register->last_receipt_number + 1;
            $register->forceFill(['last_receipt_number' => $receipt])->save();

            $before = $appointment->attributesToArray();
            $movement = CashMovement::query()->create([
                'cash_register_id' => $register->id,
                'type' => 'RECEBIMENTO',
                'payment_method_id' => $method->id,
                'gross_amount' => $gross,
                'fee_amount' => $fee,
                'net_amount' => $net,
                'appointment_id' => $appointment->id,
                'receipt_number' => $receipt,
                'created_by' => $request->user()->id,
            ]);
            $appointment->forceFill([
                'payment_status' => 'PAID',
                'paid_at' => now(),
                'payment_method_id' => $method->id,
                'cash_movement_id' => $movement->id,
            ])->save();
            $this->moveQueue($appointment, 'CHECKOUT');
            $this->audit->record($request, $appointment, 'payment.charged', $before, $appointment->attributesToArray());

            return [$appointment, $movement, $method, $register];
        }, 3);

        [$appointment, $movement, $method, $register] = $result;

        return response()->json(['data' => [
            'appointment_id' => $appointment->id,
            'payment_status' => $appointment->payment_status,
            'paid_at' => $appointment->paid_at,
            'receipt_number' => $movement->receipt_number,
            'cash_register_id' => $register->id,
            'method' => ['code' => $method->code, 'name' => $method->name],
            'gross_amount' => (string) $movement->gross_amount,
            'fee_amount' => (string) $movement->fee_amount,
            'net_amount' => (string) $movement->net_amount,
        ]], 201);
    }

    public function checkIn(Request $request, Appointment $appointment): JsonResponse
    {
        $appointment = DB::transaction(function () use ($request, $appointment): Appointment {
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointment->id);
            if ($appointment->status !== 'CONFIRMED') {
                throw ValidationException::withMessages(['appointment' => 'Somente fichas confirmadas podem fazer check-in.']);
            }
            $before = $appointment->attributesToArray();
            $appointment->forceFill(['status' => 'CHECKED_IN'])->save();
            $this->moveQueue($appointment, 'WAITING');
            $this->audit->record($request, $appointment, 'visit.checked_in', $before, $appointment->attributesToArray());

            return $appointment;
        }, 3);

        return response()->json(['data' => ['id' => $appointment->id, 'status' => $appointment->status]]);
    }

    public function startCare(Request $request, Appointment $appointment): JsonResponse
    {
        $appointment = DB::transaction(function () use ($request, $appointment): Appointment {
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointment->id);
            if ($appointment->status !== 'CHECKED_IN') {
                throw ValidationException::withMessages(['appointment' => 'O atendimento só inicia após o check-in na recepção.']);
            }
            if (! in_array($appointment->payment_status, ['PAID', 'AUTHORIZED'], true)) {
                throw ValidationException::withMessages(['appointment' => 'O atendimento só inicia com pagamento confirmado no caixa ou autorização B2B.']);
            }
            $before = $appointment->attributesToArray();
            $appointment->forceFill(['care_started_at' => now()])->save();
            $this->moveQueue($appointment, 'IN_SERVICE');
            $this->audit->record($request, $appointment, 'visit.care_started', $before, $appointment->attributesToArray());

            return $appointment;
        }, 3);

        return response()->json(['data' => ['id' => $appointment->id, 'status' => $appointment->status, 'care_started_at' => $appointment->care_started_at]]);
    }

    public function voidPayment(Request $request, Appointment $appointment): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);

        $movement = DB::transaction(function () use ($request, $appointment, $data): CashMovement {
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointment->id);
            if ($appointment->payment_status !== 'PAID' || ! $appointment->cash_movement_id) {
                throw ValidationException::withMessages(['appointment' => 'Somente fichas pagas podem ser estornadas.']);
            }
            $payoutItem = PayoutItem::query()->where('appointment_id', $appointment->id)->lockForUpdate()->first();
            if ($payoutItem) {
                $payout = Payout::query()->lockForUpdate()->findOrFail($payoutItem->payout_id);
                if (in_array($payout->status, ['CONFIRMED', 'PAID'], true)) {
                    throw ValidationException::withMessages(['payout' => 'Ficha em repasse confirmado ou pago: desfaça o repasse antes de estornar.']);
                }
                $payoutItem->delete();
                $payout->forceFill([
                    'subtotal_gross' => (string) $payout->items()->sum('gross_amount'),
                    'total_commission' => (string) $payout->items()->sum('commission_amount'),
                ])->save();
                $this->audit->record($request, $payout, 'payout.item_removed', ['appointment_id' => $appointment->id], $payout->attributesToArray());
            }
            $original = CashMovement::query()->lockForUpdate()->findOrFail($appointment->cash_movement_id);
            $register = CashRegister::query()->lockForUpdate()->findOrFail($original->cash_register_id);
            if ($register->status !== 'ABERTO') {
                throw ValidationException::withMessages(['cash_register' => 'Caixa já fechado: estorno bloqueado. Procure o supervisor para exceção auditada.']);
            }
            $receipt = $register->last_receipt_number + 1;
            $register->forceFill(['last_receipt_number' => $receipt])->save();
            $before = $appointment->attributesToArray();
            $void = CashMovement::query()->create([
                'cash_register_id' => $register->id,
                'type' => 'ESTORNO',
                'payment_method_id' => $original->payment_method_id,
                'gross_amount' => (string) $original->gross_amount,
                'fee_amount' => (string) $original->fee_amount,
                'net_amount' => (string) $original->net_amount,
                'appointment_id' => $appointment->id,
                'receipt_number' => $receipt,
                'description' => $data['reason'],
                'created_by' => $request->user()->id,
            ]);
            $appointment->forceFill([
                'payment_status' => 'PENDING',
                'paid_at' => null,
                'payment_method_id' => null,
                'cash_movement_id' => null,
            ])->save();
            $this->audit->record($request, $appointment, 'payment.voided', $before, [...$appointment->attributesToArray(), 'reason' => $data['reason']]);

            return $void;
        }, 3);

        return response()->json(['data' => $movement->load('paymentMethod:id,code,name')], 201);
    }

    public function authorizeB2B(Request $request, Appointment $appointment): JsonResponse
    {
        $data = $request->validate(['authorization_code' => ['required', 'string', 'min:3', 'max:60']]);

        $appointment = DB::transaction(function () use ($request, $appointment, $data): Appointment {
            $appointment = Appointment::query()->with('serviceOffering.agreement')->lockForUpdate()->findOrFail($appointment->id);
            if (! in_array($appointment->status, ['CONFIRMED', 'CHECKED_IN'], true)) {
                throw ValidationException::withMessages(['appointment' => 'Somente fichas confirmadas ou com check-in podem ser autorizadas.']);
            }
            if ($appointment->payment_status !== 'PENDING') {
                throw ValidationException::withMessages(['appointment' => 'Esta ficha já foi paga ou autorizada.']);
            }
            $agreement = $appointment->serviceOffering->agreement;
            if (! $agreement->active || $agreement->is_default) {
                throw ValidationException::withMessages(['appointment' => 'Autorização B2B exige convênio empresarial ativo (não o Particular padrão).']);
            }
            $today = CarbonImmutable::today(config('sistema_dez.timezone'))->toDateString();
            $overdue = B2bInvoice::query()->where('agreement_id', $agreement->id)
                ->whereIn('status', ['EMITIDA', 'ENVIADA'])
                ->where('due_at', '<', $today)
                ->whereColumn('settled_amount', '<', 'subtotal_gross')
                ->orderBy('competence')->limit(5)->get();
            if ($overdue->isNotEmpty()) {
                $competences = $overdue->map(fn (B2bInvoice $invoice): string => $invoice->competence->format('m/Y'))->implode(', ');
                throw ValidationException::withMessages(['appointment' => "Empresa {$agreement->name} possui fatura(s) vencida(s) em aberto (competência {$competences}). Regularize no módulo B2B antes de autorizar."]);
            }
            $before = $appointment->attributesToArray();
            $appointment->forceFill(['payment_status' => 'AUTHORIZED', 'b2b_authorization_code' => $data['authorization_code']])->save();
            $this->audit->record($request, $appointment, 'payment.authorized_b2b', $before, $appointment->attributesToArray());

            return $appointment;
        }, 3);

        return response()->json(['data' => ['id' => $appointment->id, 'payment_status' => $appointment->payment_status]]);
    }

    private function moveQueue(Appointment $appointment, string $status): void
    {
        $offering = $appointment->serviceOffering()->with('unit')->firstOrFail();
        $serviceDate = CarbonImmutable::parse($appointment->scheduled_at)->setTimezone($offering->unit->timezone)->toDateString();
        $entry = QueueEntry::query()->where('appointment_id', $appointment->id)->first();
        if ($entry) {
            $entry->forceFill(['status' => $status])->save();

            return;
        }
        QueueEntry::query()->create([
            'appointment_id' => $appointment->id,
            'unit_id' => $offering->unit_id,
            'service_offering_id' => $offering->id,
            'service_date' => $serviceDate,
            'checked_in_at' => now(),
            'original_sequence' => (int) (QueueEntry::query()->where('unit_id', $offering->unit_id)->where('service_date', $serviceDate)->max('original_sequence') ?? 0) + 1,
            'status' => $status,
        ]);
    }
}
