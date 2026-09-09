<?php

namespace App\Services;

use App\Enums\AsoStatus;
use App\Enums\AsoType;
use App\Models\Appointment;
use App\Models\B2bInvoice;
use App\Models\Budget;
use App\Models\MtAso;
use App\Models\Patient;
use App\Models\RoutineTask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Gera as rotinas do dia (T12): confirmações 2ª camada, retorno de orçamentos,
 * aniversariantes de consulta e faturas B2B a vencer. Idempotente por
 * (type, ref_id, due_date).
 */
class RoutineGenerator
{
    private const TYPES = [
        RoutineTask::CONFIRMACAO => 'Ligar: confirmar consulta de amanhã',
        RoutineTask::ORCAMENTO_RETORNO => 'Orçamento sem resposta: buscar o sim/não',
        RoutineTask::ANIVERSARIANTE => 'Ligação de retorno (aniversariante de consulta)',
        RoutineTask::FATURA_VENCENDO => 'Fatura B2B a vencer/vencida: conferir cobrança',
        RoutineTask::ASO_VENCENDO => 'ASO vencido/vencendo: agendar renovação',
    ];

    /** @return int quantidade criada */
    public function generate(CarbonImmutable $date): int
    {
        return DB::transaction(function () use ($date): int {
            $created = 0;

            // 1. Confirmação 2ª camada: consultas de amanhã (sem WhatsApp, viram ligação).
            $tomorrow = $date->addDay()->toDateString();
            $appointments = Appointment::query()
                ->whereIn('status', ['CONFIRMED'])
                ->whereDate('scheduled_at', $tomorrow)
                ->whereHas('serviceOffering.unit', fn ($unit) => $unit->where('operational_enabled', true))
                ->with('patient:id,name')->get();
            foreach ($appointments as $appointment) {
                $created += $this->task(
                    RoutineTask::CONFIRMACAO, 'appointments', $appointment->id,
                    self::TYPES[RoutineTask::CONFIRMACAO].': '.$appointment->patient?->name,
                    'Consulta de '.$appointment->scheduled_at->setTimezone(config('sistema_dez.timezone'))->format('H:i').' de amanhã.',
                    $date, null,
                );
            }

            // 2. Orçamentos enviados sem resposta até ontem.
            $cutoff = $date->copy()->subDay()->endOfDay();
            $budgets = Budget::query()->where('status', 'ENVIADO')
                ->whereNull('resolved_at')->where('sent_at', '<=', $cutoff->utc())
                ->with('patient:id,name')->get();
            foreach ($budgets as $budget) {
                $created += $this->task(
                    RoutineTask::ORCAMENTO_RETORNO, 'budgets', $budget->id,
                    self::TYPES[RoutineTask::ORCAMENTO_RETORNO].': '.$budget->patient?->name,
                    'Enviado em '.$budget->sent_at?->setTimezone(config('sistema_dez.timezone'))->format('d/m').'. Registrar sim/não no orçamento.',
                    $date, null,
                );
            }

            // 3. Aniversariantes de consulta: último atendimento há 6 meses (janela configurável).
            $months = (int) config('sistema_dez.routine_return_months', 6);
            $windowStart = $date->copy()->subMonths($months)->subDays(3)->startOfDay();
            $windowEnd = $date->copy()->subMonths($months)->addDays(3)->endOfDay();
            $patients = Patient::query()
                ->whereHas('appointments', fn ($query) => $query->whereIn('status', ['CONFIRMED', 'CHECKED_IN', 'DONE'])
                    ->whereBetween('scheduled_at', [$windowStart->utc(), $windowEnd->utc()]))
                ->with(['appointments' => fn ($query) => $query->whereIn('status', ['CONFIRMED', 'CHECKED_IN', 'DONE'])
                    ->whereBetween('scheduled_at', [$windowStart->utc(), $windowEnd->utc()])])
                ->get();
            foreach ($patients as $patient) {
                $created += $this->task(
                    RoutineTask::ANIVERSARIANTE, 'patients', $patient->id,
                    self::TYPES[RoutineTask::ANIVERSARIANTE].': '.$patient->name,
                    "Último atendimento próximo a {$months} meses. Retorno/care.",
                    $date, null,
                );
            }

            // 4. Faturas B2B a vencer (3 dias) ou vencidas em aberto.
            $invoices = B2bInvoice::query()->whereIn('status', ['EMITIDA', 'ENVIADA'])
                ->whereColumn('settled_amount', '<', 'subtotal_gross')
                ->where('due_at', '<=', $date->copy()->addDays(3)->toDateString())
                ->where('due_at', '>=', $date->copy()->subDays(30)->toDateString())
                ->with('agreement:id,name')->get();
            foreach ($invoices as $invoice) {
                $due = CarbonImmutable::parse($invoice->due_at);
                if ($due->greaterThan($date)) {
                    continue; // Vencendo em 3 dias gera aviso; só a partir do dia vira tarefa.
                }
                $created += $this->task(
                    RoutineTask::FATURA_VENCENDO, 'b2b_invoices', $invoice->id,
                    self::TYPES[RoutineTask::FATURA_VENCENDO].': '.$invoice->agreement?->name,
                    'Competência '.$invoice->competence->format('m/Y').', venceu em '.$due->toDateString().'.',
                    $date, null,
                );
            }

            // 5. ASO periódico vencido ou vencendo hoje (§6.7): renovação via Medicina do Trabalho.
            $asos = MtAso::query()->whereIn('status', [AsoStatus::Emitido->value, AsoStatus::Pendente->value])
                ->where('type', AsoType::Periodico)
                ->whereDate('expires_at', '<=', $date->toDateString())
                ->with(['patient:id,name', 'agreement:id,name'])->get();
            foreach ($asos as $aso) {
                $created += $this->task(
                    RoutineTask::ASO_VENCENDO, 'mt_asos', $aso->id,
                    self::TYPES[RoutineTask::ASO_VENCENDO].': '.$aso->patient?->name.' ('.$aso->agreement?->name.')',
                    'Venceu/vence em '.$aso->expires_at?->format('d/m/Y').'. Agendar exame periódico.',
                    $date, null,
                );
            }

            return $created;
        }, 3);
    }

    private function task(string $type, string $refType, string $refId, string $title, string $detail, CarbonImmutable $dueDate, ?int $assignedTo): int
    {
        $existing = RoutineTask::query()->where('type', $type)->where('ref_id', $refId)
            ->whereDate('due_date', $dueDate->toDateString())->first();
        if ($existing && $existing->status !== 'PENDENTE') {
            return 0;
        }
        if ($existing) {
            return 0;
        }
        RoutineTask::query()->create([
            'type' => $type, 'ref_type' => $refType, 'ref_id' => $refId,
            'title' => $title, 'detail' => $detail, 'assigned_to' => $assignedTo,
            'due_date' => $dueDate->toDateString(), 'status' => 'PENDENTE',
        ]);

        return 1;
    }
}
