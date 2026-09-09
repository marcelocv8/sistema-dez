<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente do fork AppChat (§6.12.1): lista linhas e envia mensagens.
 * Autenticação X-Api-Key; sem WHATSAPP_ENABLED/APPCHAT_API_KEY o cliente
 * fica desabilitado e nada sai do DEZ.
 */
class AppChatClient
{
    public function enabled(): bool
    {
        return (bool) config('sistema_dez.whatsapp.enabled') && (bool) config('sistema_dez.whatsapp.api_key');
    }

    /** @return list<array{id: int, numero: string, status_saude: string, provedor: string}> */
    public function lines(): array
    {
        $response = $this->http()->get('/integracoes/conexoes');

        if ($response->failed()) {
            throw new \RuntimeException('AppChat indisponível ('.$response->status().').');
        }

        return array_map(
            static fn (array $line): array => [
                'id' => (int) $line['id'],
                'numero' => (string) $line['numero'],
                'status_saude' => (string) ($line['status_saude'] ?? ''),
                'provedor' => (string) ($line['provedor'] ?? ''),
            ],
            array_values($response->json('conexoes') ?? []),
        );
    }

    /**
     * Envia texto para o número. A idempotency_key é única por mensagem lógica:
     * repetir a chave NÃO reenvia (replay devolve 200).
     *
     * @return array{message_key: ?string, replay: bool}
     *
     * @throws \RuntimeException falhas 4xx/5xx do AppChat (destino não resolvido, suprimido, linha indisponível, provedor recusou)
     */
    public function send(string $idempotencyKey, string $phoneE164, string $text, ?int $conexaoId = null): array
    {
        $payload = [
            'idempotency_key' => $idempotencyKey,
            'phone_e164' => $phoneE164,
            'texto' => $text,
        ];
        if ($conexaoId !== null) {
            $payload['conexao_id'] = $conexaoId;
        }

        $response = $this->http()->post('/integracoes/mensagens', $payload);
        $status = $response->status();

        if ($status >= 200 && $status < 300) {
            $data = (array) $response->json('data');

            return [
                'message_key' => isset($data['message_key']) ? (string) $data['message_key'] : null,
                'replay' => (bool) ($data['replay'] ?? false),
            ];
        }

        $detail = (string) ($response->json('error') ?? 'Erro desconhecido no AppChat.');
        Log::warning('whatsapp.send.failed', ['status' => $status, 'detail' => $detail, 'idempotency_key' => $idempotencyKey]);
        throw new \RuntimeException($detail);
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl((string) config('sistema_dez.whatsapp.base_url'))
            ->withHeaders(['X-Api-Key' => (string) config('sistema_dez.whatsapp.api_key')])
            ->acceptJson()
            ->timeout(5)
            ->connectTimeout(3);
    }
}
