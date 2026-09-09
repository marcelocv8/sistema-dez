<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\WhatsappOutbox;
use App\Services\WhatsApp\WhatsAppDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * T3 — Confirmações D-2 (§6.2.4/§6.17): agenda os disparos do DEZ. Sem resposta
 * do paciente, a 2ª camada (rotina de ligação D+1) entra pelo RoutineGenerator.
 * Idempotente: um ciclo por atendimento + dia; falhas do dia anterior são
 * reprocessadas com a mesma chave.
 */
class DispatchWhatsAppConfirmations extends Command
{
    protected $signature = 'whatsapp:dispatch-confirmations {--date= : Data de referência (Y-m-d); padrão hoje}';

    protected $description = 'Envia as confirmações D-2 via fork AppChat e reprocessa falhas anteriores';

    public function handle(WhatsAppDispatcher $dispatcher): int
    {
        if (! $dispatcher->enabled()) {
            $this->info('WhatsApp desabilitado (WHATSAPP_ENABLED/APPCHAT_API_KEY). Nada enviado.');

            return self::SUCCESS;
        }

        $tz = (string) config('sistema_dez.timezone');
        $today = $this->option('date') !== null
            ? CarbonImmutable::parse((string) $this->option('date'), $tz)
            : CarbonImmutable::today($tz);
        $target = $today->addDays(max(0, (int) config('sistema_dez.whatsapp.reminder_days')));

        $appointments = Appointment::query()
            ->whereIn('status', ['CONFIRMED', 'SCHEDULED'])
            ->whereDate('scheduled_at', $target->toDateString())
            ->whereHas('serviceOffering.unit', fn ($unit) => $unit->where('operational_enabled', true))
            ->with(['patient', 'serviceOffering.procedure', 'serviceOffering.unit'])
            ->get();

        $sent = 0;
        foreach ($appointments as $appointment) {
            $confirmation = $dispatcher->confirmationMessage($appointment, $today);
            if ($confirmation !== null && $confirmation->outbox_id !== null) {
                $sent++;
            }
        }

        $reprocessed = 0;
        $failed = WhatsappOutbox::query()
            ->where('event_key', WhatsappOutbox::D2_CONFIRMATION)
            ->where('status', 'FAILED')
            ->whereDate('updated_at', '<', $today->toDateString())
            ->with('appointment.patient')
            ->get();
        foreach ($failed as $outbox) {
            if ($outbox->appointment === null) {
                continue;
            }
            $dispatcher->send($outbox);
            if ($outbox->status === 'SENT') {
                $reprocessed++;
            }
        }

        $this->info("{$sent} confirmações D-2 processadas para {$target->toDateString()}; {$reprocessed} falhas reprocessadas.");

        return self::SUCCESS;
    }
}
