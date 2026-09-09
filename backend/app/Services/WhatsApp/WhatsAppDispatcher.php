<?php

namespace App\Services\WhatsApp;

use App\Models\Appointment;
use App\Models\AppointmentConfirmation;
use App\Models\AppSetting;
use App\Models\WhatsappOutbox;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Orquestra os disparos do DEZ pelo fork AppChat (T2): mensagem no ato do
 * agendamento (§6.2.4) e confirmação D-2 (§6.2/§6.17). Todo disparo nasce no
 * outbox; falha não derruba o fluxo de negócio (fail-open) e fica registrada
 * para reprocesso.
 */
class WhatsAppDispatcher
{
    private const SELECTED_LINE_KEY = 'whatsapp_conexao_id';

    public function __construct(private readonly AppChatClient $client) {}

    public function enabled(): bool
    {
        return $this->client->enabled();
    }

    /** Linha escolhida pelo Admin em app_settings (fallback: o AppChat escolhe sozinho). */
    public function selectedLineId(): ?int
    {
        $value = AppSetting::query()->where('key', self::SELECTED_LINE_KEY)->value('value');

        return $value !== null && $value !== '' ? (int) $value : null;
    }

    public function selectLine(?int $conexaoId, ?int $userId): void
    {
        AppSetting::set(self::SELECTED_LINE_KEY, $conexaoId !== null ? (string) $conexaoId : '', $userId);
    }

    /**
     * Mensagem automática no momento do agendamento (público e recepção).
     * Sem telefone do paciente ou integração desligada: não faz nada.
     */
    public function bookingMessage(Appointment $appointment): void
    {
        if (! $this->enabled()) {
            return;
        }
        $phone = $this->patientPhone($appointment);
        if ($phone === null) {
            return;
        }

        $text = $this->bookingText($appointment);
        if ($text === null) {
            return;
        }

        $this->dispatch(WhatsappOutbox::BOOKING, $phone, $text, $appointment, 'booking:'.$appointment->id);
    }

    /**
     * Confirmação D-2 (§6.2.4): cria/atualiza o ciclo e envia. Idempotente por
     * atendimento + dia de disparo; reusa a outbox existente em reprocesso.
     */
    public function confirmationMessage(Appointment $appointment, CarbonImmutable $requestedOn): ?AppointmentConfirmation
    {
        if (! $this->enabled()) {
            return null;
        }
        $phone = $this->patientPhone($appointment);
        if ($phone === null) {
            return null;
        }

        $confirmation = AppointmentConfirmation::query()->firstOrCreate(
            ['appointment_id' => $appointment->id, 'requested_on' => $requestedOn->toDateString()],
            ['status' => AppointmentConfirmation::PENDING],
        );

        $outbox = $confirmation->outbox_id !== null
            ? WhatsappOutbox::query()->find($confirmation->outbox_id)
            : null;

        if ($outbox === null) {
            $text = $this->confirmationText($appointment, $requestedOn);
            $outbox = $this->dispatch(
                WhatsappOutbox::D2_CONFIRMATION,
                $phone,
                $text,
                $appointment,
                'd2:'.$appointment->id.':'.$requestedOn->toDateString(),
            );
            $confirmation->forceFill(['outbox_id' => $outbox->id, 'requested_at' => now()])->save();
        } elseif ($outbox->status === 'FAILED') {
            $this->send($outbox); // reprocesso com a mesma idempotency_key
        }

        return $confirmation->fresh();
    }

    /**
     * Grava no outbox e tenta enviar (síncrono, timeout curto). Falha vira
     * outbox FAILED com o erro — o chamador decide reprocessar.
     */
    public function dispatch(string $eventKey, string $phone, string $text, ?Appointment $appointment, string $idempotencyKey): WhatsappOutbox
    {
        $outbox = WhatsappOutbox::query()->firstOrNew(['idempotency_key' => $idempotencyKey]);
        $outbox->forceFill([
            'appointment_id' => $appointment?->id,
            'event_key' => $eventKey,
            'to_phone' => $phone,
            'body' => $text,
        ]);
        if (! $outbox->exists) {
            $outbox->status = 'QUEUED';
            $outbox->save();
        }

        return $this->send($outbox);
    }

    public function send(WhatsappOutbox $outbox): WhatsappOutbox
    {
        try {
            $result = $this->client->send($outbox->idempotency_key, $outbox->to_phone, $outbox->body, $this->selectedLineId());
            $outbox->forceFill([
                'status' => 'SENT',
                'provider_message_key' => $result['message_key'],
                'error' => null,
                'sent_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $outbox->forceFill([
                'status' => 'FAILED',
                'error' => $exception->getMessage(),
            ])->save();
        }

        return $outbox;
    }

    private function patientPhone(Appointment $appointment): ?string
    {
        $raw = $appointment->patient?->phone;
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $digits = preg_replace('/\D/', '', $raw) ?? '';
        if ($digits === '') {
            return null;
        }
        if (! str_starts_with($digits, '55')) {
            $digits = '55'.$digits;
        }

        return strlen($digits) >= 12 && strlen($digits) <= 13 ? $digits : null;
    }

    private function bookingText(Appointment $appointment): ?string
    {
        $tz = config('sistema_dez.timezone');
        $scheduled = $appointment->scheduled_at?->copy()->setTimezone($tz);
        $procedure = $appointment->serviceOffering?->procedure?->name ?? 'seu atendimento';
        $unit = $appointment->serviceOffering?->unit?->name ?? 'Clínica DEZ';
        $name = $appointment->patient?->name ?? '';
        $when = $scheduled?->format('d/m H:i');

        if ($when === null) {
            return null;
        }

        $text = "Olá{$this->firstName($name)}! Sua consulta/exame ({$procedure}) na {$unit} foi agendado(a) para {$when}. Responda SIM para confirmar.";
        $link = $this->managementLink($appointment);

        return $link === null ? $text : $text.' Remarcar/cancelar: '.$link;
    }

    private function confirmationText(Appointment $appointment, CarbonImmutable $requestedOn): string
    {
        $tz = config('sistema_dez.timezone');
        $scheduled = $appointment->scheduled_at?->copy()->setTimezone($tz);
        $name = $appointment->patient?->name ?? '';
        $when = $scheduled?->format('d/m \à\s H:i') ?? 'no horário combinado';
        $text = "Olá{$this->firstName($name)}! Confirmando sua consulta/exame na Clínica DEZ {$when}. Responda SIM para confirmar ou NÃO para cancelar.";

        $link = $this->managementLink($appointment);

        return $link === null ? $text : $text.' Remarcar/cancelar: '.$link;
    }

    private function managementLink(Appointment $appointment): ?string
    {
        $base = rtrim((string) config('sistema_dez.whatsapp.booking_base_url'), '/');
        $token = $appointment->getAttribute('management_token');

        return ($base !== '' && is_string($token) && $token !== '') ? $base.'/'.$token : null;
    }

    private function firstName(string $name): string
    {
        $first = trim(explode(' ', trim($name))[0] ?? '');

        return $first === '' ? '' : ' '.$first;
    }
}
