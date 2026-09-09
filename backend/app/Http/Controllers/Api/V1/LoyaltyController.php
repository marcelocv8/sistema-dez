<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Agreement;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\LoyaltyDependent;
use App\Models\LoyaltyDue;
use App\Models\LoyaltyPlan;
use App\Models\LoyaltySubscription;
use App\Models\Patient;
use App\Models\PaymentMethod;
use App\Services\AuditRecorder;
use App\Services\LoyaltyEligibility;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LoyaltyController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit, private readonly LoyaltyEligibility $loyalty) {}

    public function plans(): JsonResponse
    {
        $plans = LoyaltyPlan::query()->with('agreement:id,name,is_default')
            ->withCount(['subscriptions as active_subscriptions_count' => fn ($query) => $query->where('status', 'ACTIVE')])
            ->orderBy('name')->get();

        return response()->json(['data' => $plans->map(fn (LoyaltyPlan $plan): array => $this->serializePlan($plan))->all()]);
    }

    public function storePlan(Request $request): JsonResponse
    {
        $data = $this->validatePlan($request);

        $plan = DB::transaction(function () use ($request, $data): LoyaltyPlan {
            $agreement = Agreement::query()->lockForUpdate()->findOrFail($data['agreement_id']);
            if (! $agreement->active) {
                throw ValidationException::withMessages(['agreement_id' => 'O convênio informado está inativo.']);
            }
            if ($agreement->is_default) {
                throw ValidationException::withMessages(['agreement_id' => 'O convênio Particular padrão não pode ser usado em um plano Sou DEZ.']);
            }
            if (LoyaltyPlan::query()->where('agreement_id', $agreement->id)->exists()) {
                throw ValidationException::withMessages(['agreement_id' => 'Já existe um plano Sou DEZ vinculado a este convênio.']);
            }
            $plan = LoyaltyPlan::query()->create($data);
            $this->audit->record($request, $plan, 'loyalty.plan_created', null, $plan->attributesToArray());

            return $plan;
        }, 3);

        return response()->json(['data' => $this->serializePlan($plan->load('agreement:id,name,is_default'))], 201);
    }

    public function updatePlan(Request $request, LoyaltyPlan $loyaltyPlan): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:3', 'max:120'],
            'monthly_fee' => ['sometimes', 'numeric', 'min:1', 'max:100000'],
            'due_day' => ['sometimes', 'integer', 'min:1', 'max:28'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $plan = DB::transaction(function () use ($request, $loyaltyPlan, $data): LoyaltyPlan {
            $plan = LoyaltyPlan::query()->lockForUpdate()->findOrFail($loyaltyPlan->id);
            $before = $plan->attributesToArray();
            $plan->fill($data)->save();
            $this->audit->record($request, $plan, 'loyalty.plan_updated', $before, $plan->attributesToArray());

            return $plan;
        }, 3);

        return response()->json(['data' => $this->serializePlan($plan->load('agreement:id,name,is_default'))]);
    }

    public function subscriptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['ACTIVE', 'CANCELED', 'BLOCKED'])],
            'search' => ['nullable', 'string', 'max:120'],
        ]);
        $search = $data['search'] ?? null;

        $subscriptions = LoyaltySubscription::query()->with([
            'plan:id,name,agreement_id,monthly_fee,due_day',
            'plan.agreement:id,name',
            'patient:id,name,cpf_last_four',
            'dependents.patient:id,name,cpf_last_four',
            'dues' => fn ($query) => $query->orderBy('competence'),
        ])
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($search !== null && $search !== '', function ($query) use ($search): void {
                if (preg_match('/^\d{11}$/', preg_replace('/\D/', '', $search) ?? '')) {
                    $hash = hash_hmac('sha256', preg_replace('/\D/', '', $search), (string) config('sistema_dez.identity_pepper'));
                    $patientIds = Patient::query()->where('cpf_hash', $hash)->pluck('id');
                    $query->where(fn ($inner) => $inner->whereIn('patient_id', $patientIds)
                        ->orWhereHas('dependents', fn ($dependents) => $dependents->whereIn('patient_id', $patientIds)));
                } else {
                    $query->where(fn ($inner) => $inner->whereHas('patient', fn ($patient) => $patient->whereRaw('name ILIKE ?', ['%'.$search.'%']))
                        ->orWhereHas('dependents.patient', fn ($patient) => $patient->whereRaw('name ILIKE ?', ['%'.$search.'%'])));
                }
            })
            ->orderByDesc('created_at')->limit(100)->get();

        return response()->json(['data' => $subscriptions->map(fn (LoyaltySubscription $subscription): array => $this->serializeSubscription($subscription))->all()]);
    }

    public function showSubscription(LoyaltySubscription $loyaltySubscription): JsonResponse
    {
        return response()->json(['data' => $this->serializeSubscription($loyaltySubscription->load([
            'plan:id,name,agreement_id,monthly_fee,due_day', 'plan.agreement:id,name',
            'patient:id,name,cpf_last_four', 'dependents.patient:id,name,cpf_last_four',
            'dues' => fn ($query) => $query->orderBy('competence'),
        ]))]);
    }

    public function storeSubscription(Request $request): JsonResponse
    {
        $data = $this->validateSubscriptionPayload($request);

        $subscription = DB::transaction(function () use ($request, $data): LoyaltySubscription {
            $plan = LoyaltyPlan::query()->lockForUpdate()->findOrFail($data['plan_id']);
            if (! $plan->active) {
                throw ValidationException::withMessages(['plan_id' => 'Este plano Sou DEZ está inativo.']);
            }
            $titular = $this->resolvePatient($data['patient']);
            if ($this->loyalty->hasAnyActiveSubscription($titular->id)) {
                throw ValidationException::withMessages(['patient' => 'O paciente já possui assinatura Sou DEZ ativa (titular ou dependente).']);
            }
            $subscription = LoyaltySubscription::query()->create([
                'plan_id' => $plan->id,
                'patient_id' => $titular->id,
                'status' => 'ACTIVE',
                'started_at' => CarbonImmutable::today((string) config('sistema_dez.timezone')),
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);
            $this->generateDue($subscription, $plan);
            $this->syncDependents($request, $subscription, $data['dependents'] ?? []);
            $this->audit->record($request, $subscription, 'loyalty.subscription_created', null, $subscription->attributesToArray());

            return $subscription;
        }, 3);

        return response()->json(['data' => $this->serializeSubscription($subscription->load([
            'plan:id,name,agreement_id,monthly_fee,due_day', 'plan.agreement:id,name',
            'patient:id,name,cpf_last_four', 'dependents.patient:id,name,cpf_last_four', 'dues',
        ]))], 201);
    }

    public function updateSubscription(Request $request, LoyaltySubscription $loyaltySubscription): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['ACTIVE', 'CANCELED', 'BLOCKED'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'dependents' => ['nullable', 'array', 'max:10'],
            'dependents.*.cpf' => ['required', 'string', 'max:20'],
            'dependents.*.name' => ['nullable', 'string', 'max:150'],
        ]);

        $subscription = DB::transaction(function () use ($request, $loyaltySubscription, $data): LoyaltySubscription {
            $subscription = LoyaltySubscription::query()->with('plan')->lockForUpdate()->findOrFail($loyaltySubscription->id);
            $before = $subscription->attributesToArray();
            if (isset($data['status'])) {
                $this->applyStatus($subscription, $data['status']);
            }
            if (array_key_exists('notes', $data)) {
                $subscription->forceFill(['notes' => $data['notes']])->save();
            }
            if ($request->has('dependents')) {
                $this->syncDependents($request, $subscription, $data['dependents'] ?? []);
            }
            $subscription->refresh();
            $this->audit->record($request, $subscription, 'loyalty.subscription_updated', $before, $subscription->attributesToArray());

            return $subscription;
        }, 3);

        return response()->json(['data' => $this->serializeSubscription($subscription->load([
            'plan:id,name,agreement_id,monthly_fee,due_day', 'plan.agreement:id,name',
            'patient:id,name,cpf_last_four', 'dependents.patient:id,name,cpf_last_four', 'dues',
        ]))]);
    }

    public function dues(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['PENDENTE', 'PAGA'])],
            'overdue' => ['nullable', 'boolean'],
        ]);
        $today = CarbonImmutable::today((string) config('sistema_dez.timezone'))->toDateString();

        $dues = LoyaltyDue::query()->with(['subscription.patient:id,name,cpf_last_four', 'subscription.plan:id,name'])
            ->when($data['overdue'] ?? false, fn ($query) => $query->where('status', 'PENDENTE')->where('due_date', '<', $today))
            ->when(isset($data['status']) && ! ($data['overdue'] ?? false), fn ($query) => $query->where('status', $data['status']))
            ->orderBy('due_date')->limit(200)->get();

        return response()->json(['data' => $dues->map(fn (LoyaltyDue $due): array => $this->serializeDue($due))->all()]);
    }

    public function collect(Request $request, LoyaltyDue $loyaltyDue): JsonResponse
    {
        if (! $request->user()->hasPermission('cash.operate')) {
            return new JsonResponse(['message' => 'Somente operador de caixa pode receber mensalidades.'], 403);
        }
        $data = $request->validate([
            'payment_method_code' => ['required', 'string', 'max:20'],
            'unit_id' => ['required', 'uuid', 'exists:units,id'],
        ]);

        $due = DB::transaction(function () use ($request, $loyaltyDue, $data): LoyaltyDue {
            $due = LoyaltyDue::query()->with(['subscription.plan', 'subscription.patient:id,name,cpf_last_four'])
                ->lockForUpdate()->findOrFail($loyaltyDue->id);
            if ($due->status !== 'PENDENTE') {
                throw ValidationException::withMessages(['due' => 'Esta mensalidade não está pendente.']);
            }
            $register = CashRegister::query()->where('unit_id', $data['unit_id'])->where('status', 'ABERTO')->lockForUpdate()->first();
            if (! $register) {
                throw ValidationException::withMessages(['cash_register' => 'Nenhum caixa aberto para esta unidade. Abra o caixa antes de receber a mensalidade.']);
            }
            $method = PaymentMethod::query()->where('code', mb_strtoupper($data['payment_method_code']))->where('active', true)->first();
            if (! $method) {
                throw ValidationException::withMessages(['payment_method_code' => 'Forma de pagamento inválida ou inativa.']);
            }

            $gross = (string) $due->amount;
            $fee = bcdiv(bcmul($gross, (string) $method->extra_percent, 4), '100', 2);
            $net = bcadd($gross, $fee, 2);
            $receipt = $register->last_receipt_number + 1;
            $register->forceFill(['last_receipt_number' => $receipt])->save();
            $movement = CashMovement::query()->create([
                'cash_register_id' => $register->id,
                'type' => 'MENSALIDADE',
                'payment_method_id' => $method->id,
                'gross_amount' => $gross,
                'fee_amount' => $fee,
                'net_amount' => $net,
                'loyalty_due_id' => $due->id,
                'receipt_number' => $receipt,
                'description' => 'Mensalidade Sou DEZ - '.$due->subscription->plan->name,
                'created_by' => $request->user()->id,
            ]);
            $before = $due->attributesToArray();
            $due->forceFill(['status' => 'PAGA', 'paid_at' => now(), 'cash_movement_id' => $movement->id, 'collected_by' => $request->user()->id])->save();
            $this->generateDue($due->subscription, $due->subscription->plan, $due->competence);
            $this->audit->record($request, $due, 'loyalty.due_collected', $before, $due->attributesToArray());

            return $due;
        }, 3);

        return response()->json(['data' => $this->serializeDue($due->load(['subscription.patient:id,name,cpf_last_four', 'subscription.plan:id,name']))], 201);
    }

    public function eligibility(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agreement_id' => ['required', 'uuid', 'exists:agreements,id'],
            'patient_id' => ['required', 'uuid', 'exists:patients,id'],
        ]);
        $plan = $this->loyalty->planForAgreement($data['agreement_id']);
        if (! $plan) {
            return response()->json(['data' => ['eligible' => true, 'reason' => null, 'plan' => null, 'subscription' => null]]);
        }
        $eligibility = $this->loyalty->check($plan, Patient::query()->findOrFail($data['patient_id']));

        return response()->json(['data' => [
            'eligible' => $eligibility['eligible'],
            'reason' => $eligibility['reason'],
            'plan' => ['id' => $plan->id, 'name' => $plan->name],
            'subscription' => $eligibility['subscription'] ? [
                'id' => $eligibility['subscription']->id,
                'status' => $eligibility['subscription']->status,
            ] : null,
        ]]);
    }

    /**
     * @param  array<string, mixed>  $patientInput
     * @param  list<array<string, mixed>>  $dependentInputs
     */
    private function syncDependents(Request $request, LoyaltySubscription $subscription, array $dependentInputs): void
    {
        $requestedIds = [];
        foreach ($dependentInputs as $input) {
            $patient = $this->resolvePatient($input);
            if ($patient->id === $subscription->patient_id) {
                throw ValidationException::withMessages(['dependents' => 'O titular não pode ser dependente da própria assinatura.']);
            }
            if (LoyaltySubscription::query()->where('patient_id', $patient->id)->where('status', 'ACTIVE')->exists()) {
                throw ValidationException::withMessages(['dependents' => "{$patient->name} já é titular de uma assinatura Sou DEZ ativa e não pode ser dependente."]);
            }
            if (LoyaltyDependent::query()->where('patient_id', $patient->id)->where('active', true)
                ->whereHas('subscription', fn ($query) => $query->where('status', 'ACTIVE'))
                ->where('subscription_id', '!=', $subscription->id)->exists()) {
                throw ValidationException::withMessages(['dependents' => "{$patient->name} já é dependente de outra assinatura Sou DEZ ativa."]);
            }
            $requestedIds[] = $patient->id;
            LoyaltyDependent::query()->updateOrCreate(
                ['subscription_id' => $subscription->id, 'patient_id' => $patient->id],
                ['active' => true],
            );
        }
        LoyaltyDependent::query()->where('subscription_id', $subscription->id)
            ->whereNotIn('patient_id', $requestedIds)->where('active', true)
            ->each(function (LoyaltyDependent $dependent) use ($request): void {
                $before = $dependent->attributesToArray();
                $dependent->forceFill(['active' => false])->save();
                $this->audit->record($request, $dependent, 'loyalty.dependent_removed', $before, $dependent->attributesToArray());
            });
    }

    /**
     * @param  array<string, mixed>  $patientInput
     */
    private function resolvePatient(array $patientInput): Patient
    {
        $cpf = preg_replace('/\D/', '', (string) ($patientInput['cpf'] ?? ''));
        if (! is_string($cpf) || preg_match('/^\d{11}$/', $cpf) !== 1) {
            throw ValidationException::withMessages(['patient.cpf' => 'CPF inválido.']);
        }
        $cpfHash = hash_hmac('sha256', $cpf, (string) config('sistema_dez.identity_pepper'));
        $patient = Patient::query()->where('cpf_hash', $cpfHash)->first();
        if ($patient) {
            return $patient;
        }
        $name = trim((string) ($patientInput['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['patient.name' => 'Paciente novo: informe o nome para cadastrar a assinatura.']);
        }

        return Patient::query()->create([
            'name' => $name,
            'cpf_hash' => $cpfHash,
            'cpf_last_four' => substr($cpf, -4),
            'birth_date' => $patientInput['birth_date'] ?? null,
            'phone' => $patientInput['phone'] ?? null,
            'privacy_consent_at' => now(),
            'privacy_consent_source' => 'RECEPTION',
        ]);
    }

    private function applyStatus(LoyaltySubscription $subscription, string $status): void
    {
        if ($status === 'ACTIVE' && $subscription->status !== 'ACTIVE') {
            $conflict = LoyaltySubscription::query()->where('patient_id', $subscription->patient_id)
                ->where('status', 'ACTIVE')->where('id', '!=', $subscription->id)->exists()
                || LoyaltyDependent::query()->where('patient_id', $subscription->patient_id)->where('active', true)
                    ->whereHas('subscription', fn ($query) => $query->where('status', 'ACTIVE'))->exists();
            if ($conflict) {
                throw ValidationException::withMessages(['status' => 'Não é possível reativar: o paciente já participa de outra assinatura ativa (titular ou dependente). Resolva os vínculos antes de reativar.']);
            }
        }
        $subscription->forceFill([
            'status' => $status,
            'ended_at' => $status === 'CANCELED' ? CarbonImmutable::today((string) config('sistema_dez.timezone')) : null,
        ])->save();
    }

    private function generateDue(LoyaltySubscription $subscription, LoyaltyPlan $plan, ?CarbonImmutable $fromCompetence = null): LoyaltyDue
    {
        $competence = ($fromCompetence
            ? CarbonImmutable::parse($fromCompetence)->addMonth()
            : CarbonImmutable::today((string) config('sistema_dez.timezone'))
        )->startOfMonth();
        $dueDate = $competence->setDay(min($plan->due_day, $competence->daysInMonth));
        if ($dueDate->lessThan(CarbonImmutable::today((string) config('sistema_dez.timezone')))) {
            $dueDate = CarbonImmutable::today((string) config('sistema_dez.timezone'));
        }

        return LoyaltyDue::query()->firstOrCreate(
            ['subscription_id' => $subscription->id, 'competence' => $competence->toDateString()],
            ['amount' => $plan->monthly_fee, 'due_date' => $dueDate->toDateString(), 'status' => 'PENDENTE'],
        );
    }

    /** @return array<string, mixed> */
    private function validatePlan(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'agreement_id' => ['required', 'uuid', 'exists:agreements,id'],
            'monthly_fee' => ['required', 'numeric', 'min:1', 'max:100000'],
            'due_day' => ['required', 'integer', 'min:1', 'max:28'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function validateSubscriptionPayload(Request $request): array
    {
        $request->merge(['patient.cpf' => preg_replace('/\D/', '', (string) $request->input('patient.cpf'))]);
        if ($request->has('dependents')) {
            $request->merge([
                'dependents' => collect($request->input('dependents', []))
                    ->map(fn ($item) => [...$item, 'cpf' => preg_replace('/\D/', '', (string) ($item['cpf'] ?? ''))])->all(),
            ]);
        }

        return $request->validate([
            'plan_id' => ['required', 'uuid', 'exists:loyalty_plans,id'],
            'patient.cpf' => ['required', 'digits:11'],
            'patient.name' => ['sometimes', 'string', 'max:150'],
            'patient.birth_date' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'patient.phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'dependents' => ['nullable', 'array', 'max:10'],
            'dependents.*.cpf' => ['required', 'digits:11'],
            'dependents.*.name' => ['nullable', 'string', 'max:150'],
            'dependents.*.birth_date' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'dependents.*.phone' => ['nullable', 'string', 'max:30'],
        ]);
    }

    /** @return array<string, mixed> */
    private function serializePlan(LoyaltyPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'monthly_fee' => (string) $plan->monthly_fee,
            'due_day' => $plan->due_day,
            'active' => $plan->active,
            'agreement' => $plan->relationLoaded('agreement') && $plan->agreement
                ? ['id' => $plan->agreement->id, 'name' => $plan->agreement->name, 'is_default' => $plan->agreement->is_default]
                : ['id' => $plan->agreement_id, 'name' => null, 'is_default' => false],
            'active_subscriptions_count' => $plan->active_subscriptions_count ?? $plan->subscriptions()->where('status', 'ACTIVE')->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeSubscription(LoyaltySubscription $subscription): array
    {
        $today = CarbonImmutable::today((string) config('sistema_dez.timezone'));

        return [
            'id' => $subscription->id,
            'status' => $subscription->status,
            'started_at' => $subscription->started_at?->toDateString(),
            'ended_at' => $subscription->ended_at?->toDateString(),
            'notes' => $subscription->notes,
            'plan' => $subscription->relationLoaded('plan') ? [
                'id' => $subscription->plan->id,
                'name' => $subscription->plan->name,
                'monthly_fee' => (string) $subscription->plan->monthly_fee,
                'due_day' => $subscription->plan->due_day,
                'agreement' => $subscription->plan->relationLoaded('agreement') && $subscription->plan->agreement
                    ? ['id' => $subscription->plan->agreement->id, 'name' => $subscription->plan->agreement->name]
                    : ['id' => $subscription->plan->agreement_id, 'name' => null],
            ] : null,
            'patient' => $subscription->relationLoaded('patient') ? [
                'id' => $subscription->patient->id,
                'name' => $subscription->patient->name,
                'cpf_last_four' => $subscription->patient->cpf_last_four,
            ] : null,
            'dependents' => $subscription->relationLoaded('dependents') ? $subscription->dependents
                ->sortByDesc('active')->values()
                ->map(fn (LoyaltyDependent $dependent): array => [
                    'id' => $dependent->id,
                    'active' => $dependent->active,
                    'patient' => ['id' => $dependent->patient?->id, 'name' => $dependent->patient?->name, 'cpf_last_four' => $dependent->patient?->cpf_last_four],
                ])->all() : [],
            'dues' => $subscription->relationLoaded('dues') ? $subscription->dues->map(fn (LoyaltyDue $due): array => [
                'id' => $due->id,
                'competence' => $due->competence?->toDateString(),
                'amount' => (string) $due->amount,
                'due_date' => $due->due_date?->toDateString(),
                'status' => $due->status,
                'overdue' => $due->isOverdue($today),
                'paid_at' => $due->paid_at?->toIso8601String(),
            ])->all() : [],
            'overdue_count' => $subscription->relationLoaded('dues')
                ? $subscription->dues->filter(fn (LoyaltyDue $due): bool => $due->isOverdue($today))->count()
                : $subscription->dues()->where('status', 'PENDENTE')->where('due_date', '<', $today->toDateString())->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeDue(LoyaltyDue $due): array
    {
        $today = CarbonImmutable::today((string) config('sistema_dez.timezone'));

        return [
            'id' => $due->id,
            'competence' => $due->competence?->toDateString(),
            'amount' => (string) $due->amount,
            'due_date' => $due->due_date?->toDateString(),
            'status' => $due->status,
            'overdue' => $due->isOverdue($today),
            'paid_at' => $due->paid_at?->toIso8601String(),
            'subscription' => $due->relationLoaded('subscription') ? [
                'id' => $due->subscription->id,
                'plan_name' => $due->subscription->plan?->name,
                'patient' => $due->subscription->relationLoaded('patient') ? [
                    'id' => $due->subscription->patient?->id,
                    'name' => $due->subscription->patient?->name,
                    'cpf_last_four' => $due->subscription->patient?->cpf_last_four,
                ] : null,
            ] : null,
        ];
    }
}
