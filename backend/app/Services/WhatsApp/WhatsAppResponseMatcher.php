<?php

namespace App\Services\WhatsApp;

use App\Models\AppointmentConfirmation;
use App\Models\WhatsappEvent;
use App\Models\WhatsappOutbox;
use App\Services\AuditRecorder;
use Illuminate\Http\Request;

/**
 * Casa os eventos entregues pelo AppChat (webhook via API Hermes) com o estado
 * do DEZ (T2): respostas SIM/NÃO de confirmações D-2 e receipts de entrega.
 * Processamento idempotente por provider_event_id — replay é no-op.
 */
class WhatsAppResponseMatcher
{
    private const YES = ['sim', 's', '1', 'confirmo', 'confirmado', 'ok', 'pode'];

    private const NO = ['nao', 'n', '2', 'cancela', 'cancelar', 'cancelado'];

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Grava o evento (append-only, dedupe por provider_event_id) e processa.
     *
     * @return array{result: string, duplicate: bool}
     */
    public function handle(Request $request, string $type, string $eventId, array $payload): array
    {
        $existing = WhatsappEvent::query()->where('provider_event_id', $eventId)->first();
        if ($existing) {
            return ['result' => (string) ($existing->result ?? 'DUPLICATE'), 'duplicate' => true];
        }

        $messageKey = isset($payload['message_key']) ? (string) $payload['message_key'] : null;
        $phone = isset($payload['phone_e164']) ? $this->normalizePhone((string) $payload['phone_e164']) : null;

        $event = WhatsappEvent::query()->create([
            'provider_event_id' => $eventId,
            'type' => $type,
            'phone' => $phone,
            'message_key' => $messageKey,
            'payload' => $payload,
            'result' => null,
        ]);

        $result = match ($type) {
            'mensagem_recebida' => $this->matchInbound($request, $event, $phone, (string) ($payload['texto'] ?? '')),
            'mensagem_status' => $this->matchStatus($messageKey, (string) ($payload['status'] ?? '')),
            default => 'IGNORED',
        };

        $event->forceFill(['result' => $result])->save();

        return ['result' => $result, 'duplicate' => false];
    }

    /** Respostas do paciente (SIM/NÃO) atualizam a confirmação D-2 mais recente pendente. */
    private function matchInbound(Request $request, WhatsappEvent $event, ?string $phone, string $text): string
    {
        $answer = $this->answerOf($text);
        if ($phone === null || $answer === null) {
            return 'IGNORED';
        }

        $confirmation = $this->pendingConfirmationForPhone($phone);
        if ($confirmation === null) {
            return 'IGNORED'; // mensagem válida, mas sem confirmação pendente — fica no log
        }

        $before = ['status' => $confirmation->status];
        $confirmation->forceFill([
            'status' => $answer,
            'responded_at' => now(),
        ])->save();

        $this->audit->record($request, $confirmation, 'whatsapp.confirmation_answered', $before, ['status' => $answer]);

        return $answer;
    }

    /** Receipts do AppChat atualizam a outbox (monotônico: nunca regride). */
    private function matchStatus(?string $messageKey, string $status): string
    {
        if ($messageKey === null || $messageKey === '') {
            return 'IGNORED';
        }

        $outbox = WhatsappOutbox::query()->where('provider_message_key', $messageKey)->first();
        if ($outbox === null) {
            return 'IGNORED';
        }

        $mapped = match ($status) {
            'entregue' => 'DELIVERED',
            'lida' => 'READ',
            'falha' => 'FAILED',
            default => null,
        };
        if ($mapped === null) {
            return 'IGNORED';
        }

        if ($mapped === 'FAILED' && in_array($outbox->status, ['DELIVERED', 'READ'], true)) {
            return 'STATUS'; // não regride receipt
        }
        if (in_array($outbox->status, [$mapped, 'READ'], true)) {
            return 'STATUS';
        }

        $outbox->forceFill(['status' => $mapped])->save();

        return 'STATUS';
    }

    /** Confirmação pendente mais recente cujo telefone normalizado do paciente bate. */
    private function pendingConfirmationForPhone(string $phone): ?AppointmentConfirmation
    {
        $candidates = AppointmentConfirmation::query()
            ->where('status', AppointmentConfirmation::PENDING)
            ->whereDate('requested_on', '>=', now()->subDays(7)->toDateString())
            ->whereHas('appointment', fn ($query) => $query->whereIn('status', ['CONFIRMED', 'SCHEDULED']))
            ->with('appointment.patient:id,phone')
            ->orderByDesc('requested_on')
            ->limit(200)
            ->get();

        foreach ($candidates as $confirmation) {
            if ($this->normalizePhone((string) ($confirmation->appointment->patient?->phone ?? '')) === $phone) {
                return $confirmation;
            }
        }

        return null;
    }

    private function answerOf(string $text): ?string
    {
        // Minúsculas PRIMEIRO (acentos maiúsculos, ex. "NÃO", nunca casariam).
        $normalized = str_replace(
            ['á', 'â', 'ã', 'à', 'é', 'ê', 'í', 'ó', 'ô', 'õ', 'ú', 'ç'],
            ['a', 'a', 'a', 'a', 'e', 'e', 'i', 'o', 'o', 'o', 'u', 'c'],
            mb_strtolower(trim($text)),
        );
        // Pontuação não faz parte da resposta ("não," e "sim!" contam).
        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', '', $normalized) ?? '';
        $first = trim(explode(' ', trim($normalized))[0] ?? '');

        return match (true) {
            $first !== '' && in_array($first, self::YES, true) => AppointmentConfirmation::CONFIRMED,
            $first !== '' && in_array($first, self::NO, true) => AppointmentConfirmation::DECLINED,
            default => null,
        };
    }

    /** Normaliza para dígitos com prefixo 55 (mesma semântica do AppChat). */
    private function normalizePhone(string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', $raw) ?? '';
        if ($digits === '') {
            return null;
        }
        if (! str_starts_with($digits, '55') && strlen($digits) <= 11) {
            $digits = '55'.$digits;
        }

        return strlen($digits) >= 12 && strlen($digits) <= 13 ? $digits : null;
    }
}
