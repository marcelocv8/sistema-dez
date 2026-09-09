<?php

namespace App\Http\Middleware;

use App\Models\AgentRequest;
use App\Models\AgentToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAgentToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->bearerToken();
        if ($header === '') {
            return response()->json(['message' => 'Token de agente ausente.'], 401);
        }

        $hash = hash_hmac('sha256', $header, (string) config('sistema_dez.management_token_secret'));
        $token = AgentToken::query()->where('token_hash', $hash)->where('active', true)->whereNull('revoked_at')->first();

        if ($token === null) {
            return response()->json(['message' => 'Token de agente inválido ou revogado.'], 401);
        }

        $request->attributes->set('agent_token_id', $token->id);
        $request->attributes->set('agent_token', $token);

        $startedAt = microtime(true);
        $response = $next($request);

        $token->forceFill(['last_used_at' => now()])->save();
        AgentRequest::query()->create([
            'agent_token_id' => $token->id,
            'endpoint' => mb_substr($request->getPathInfo(), 0, 120),
            'method' => mb_substr($request->getMethod(), 0, 10),
            'status_code' => $response->getStatusCode(),
            'duration_ms' => (int) max(0, round((microtime(true) - $startedAt) * 1000)),
        ]);

        return $response;
    }
}
