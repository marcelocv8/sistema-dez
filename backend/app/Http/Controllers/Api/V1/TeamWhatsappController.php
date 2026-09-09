<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\WhatsApp\AppChatClient;
use App\Services\WhatsApp\WhatsAppDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Configuração da linha de envio do WhatsApp (T2) — Admin/Supervisor (team.manage). */
class TeamWhatsappController extends Controller
{
    public function __construct(private readonly AppChatClient $client, private readonly WhatsAppDispatcher $dispatcher) {}

    public function lines(): JsonResponse
    {
        if (! $this->client->enabled()) {
            return response()->json(['data' => [
                'enabled' => false,
                'lines' => [],
                'selected' => null,
                'error' => null,
            ]]);
        }

        try {
            $lines = $this->client->lines();
            $error = null;
        } catch (\Throwable $exception) {
            $lines = [];
            $error = $exception->getMessage();
        }

        return response()->json(['data' => [
            'enabled' => true,
            'lines' => $lines,
            'selected' => $this->dispatcher->selectedLineId(),
            'error' => $error,
        ]]);
    }

    public function selectLine(Request $request): JsonResponse
    {
        $data = $request->validate([
            'conexao_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $this->dispatcher->selectLine($data['conexao_id'] ?? null, $request->user()?->id);

        return response()->json(['data' => ['selected' => $data['conexao_id'] ?? null]]);
    }
}
