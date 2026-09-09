<?php

namespace App\Services;

use App\Models\LoyaltyDependent;
use App\Models\LoyaltyDue;
use App\Models\LoyaltyPlan;
use App\Models\LoyaltySubscription;
use App\Models\Patient;
use Carbon\CarbonImmutable;

class LoyaltyEligibility
{
    public function planForAgreement(string $agreementId): ?LoyaltyPlan
    {
        /** @var LoyaltyPlan|null $plan */
        return LoyaltyPlan::query()->where('agreement_id', $agreementId)->where('active', true)->first();
    }

    /** @return array{eligible: bool, reason: ?string, subscription: ?LoyaltySubscription} */
    public function check(LoyaltyPlan $plan, Patient $patient): array
    {
        $subscription = $this->activeSubscriptionFor($plan->id, $patient->id);
        if (! $subscription) {
            return ['eligible' => false, 'reason' => 'O paciente não possui assinatura Sou DEZ ativa para este plano. Cadastre a assinatura no módulo Sou DEZ ou use o convênio Particular.', 'subscription' => null];
        }
        $overdue = LoyaltyDue::query()->where('subscription_id', $subscription->id)
            ->where('status', 'PENDENTE')
            ->where('due_date', '<', CarbonImmutable::today((string) config('sistema_dez.timezone'))->toDateString())
            ->exists();
        if ($overdue) {
            return ['eligible' => false, 'reason' => 'O paciente possui mensalidade em atraso no programa Sou DEZ. Regularize no módulo Sou DEZ (caixa) antes de prosseguir.', 'subscription' => $subscription];
        }

        return ['eligible' => true, 'reason' => null, 'subscription' => $subscription];
    }

    public function activeSubscriptionFor(string $planId, string $patientId): ?LoyaltySubscription
    {
        /** @var LoyaltySubscription|null $subscription */
        $subscription = LoyaltySubscription::query()
            ->where('plan_id', $planId)->where('patient_id', $patientId)->where('status', 'ACTIVE')->first();
        if ($subscription) {
            return $subscription;
        }

        /** @var LoyaltySubscription|null $viaDependent */
        $viaDependent = LoyaltySubscription::query()
            ->where('plan_id', $planId)->where('status', 'ACTIVE')
            ->whereHas('dependents', fn ($query) => $query->where('patient_id', $patientId)->where('active', true))
            ->first();

        return $viaDependent;
    }

    public function hasAnyActiveSubscription(string $patientId): bool
    {
        $titular = LoyaltySubscription::query()->where('patient_id', $patientId)->where('status', 'ACTIVE')->exists();
        $dependent = LoyaltyDependent::query()->where('patient_id', $patientId)->where('active', true)
            ->whereHas('subscription', fn ($query) => $query->where('status', 'ACTIVE'))->exists();

        return $titular || $dependent;
    }
}
