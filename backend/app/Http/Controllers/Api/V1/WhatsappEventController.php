<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\WhatsApp\WhatsAppResponseMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhook do AppChat (T2): eventos de mensagens recebidas e receipts de entrega.
 * Autenticado pela API de integração (agent.token + escopo whatsapp.events) —
 * AppChat é um consumidor da API Hermes, com auditoria em agent_requests.
 */
class WhatsappEventController extends Controller
{
    public function __construct(private readonly WhatsAppResponseMatcher $matcher) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event' => ['required', 'string', 'in:mensagem_recebida,mensagem_status'],
            'event_id' => ['required', 'string', 'max:191'],
            'payload' => ['required', 'array'],
        ]);

        $result = $this->matcher->handle($request, $data['event'], $data['event_id'], $data['payload']);

        return response()->json(['data' => $result]);
    }
}
