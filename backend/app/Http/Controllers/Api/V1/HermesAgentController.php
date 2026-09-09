<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentToken;
use App\Services\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HermesAgentController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function index(): JsonResponse
    {
        $tokens = AgentToken::query()->orderByDesc('created_at')->get(['id', 'name', 'scopes', 'active', 'last_used_at', 'revoked_at', 'created_at']);

        return response()->json(['data' => $tokens->map(fn (AgentToken $token): array => $this->serialize($token))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(AgentToken::allowedScopes())],
        ]);

        $plaintext = 'dez_'.bin2hex(random_bytes(24));
        $token = AgentToken::query()->create([
            'name' => $data['name'],
            'token_hash' => hash_hmac('sha256', $plaintext, (string) config('sistema_dez.management_token_secret')),
            'scopes' => array_values($data['scopes']),
            'active' => true,
            'created_by' => $request->user()->id,
        ]);

        $this->audit->record($request, $token, 'hermes.agent.created', null, ['name' => $token->name, 'scopes' => $token->scopes]);

        return response()->json(['data' => [
            ...$this->serialize($token),
            'token' => $plaintext,
            'warning' => 'Guarde este token agora: ele não será exibido novamente.',
        ]], 201);
    }

    public function revoke(Request $request, AgentToken $agentToken): JsonResponse
    {
        if ($agentToken->revoked_at !== null) {
            return response()->json(['data' => $this->serialize($agentToken)]);
        }

        $before = $agentToken->attributesToArray();
        $agentToken->forceFill(['revoked_at' => now(), 'active' => false])->save();
        $this->audit->record($request, $agentToken, 'hermes.agent.revoked', $before, $agentToken->attributesToArray());

        return response()->json(['data' => $this->serialize($agentToken->fresh())]);
    }

    /** @return array<string, mixed> */
    private function serialize(AgentToken $token): array
    {
        return [
            'id' => $token->id,
            'name' => $token->name,
            'scopes' => $token->scopes,
            'active' => $token->active,
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'revoked_at' => $token->revoked_at?->toIso8601String(),
            'created_at' => $token->created_at?->toIso8601String(),
        ];
    }
}
