<?php

namespace App\Http\Middleware;

use App\Models\AgentToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAgentScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $token = $request->attributes->get('agent_token');

        if (! $token instanceof AgentToken || ! $token->hasScope($scope)) {
            return response()->json(['message' => 'O agente não possui o escopo '.$scope.'.'], 403);
        }

        return $next($request);
    }
}
