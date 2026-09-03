<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (! $request->user()?->hasPermission($permission)) {
            return new JsonResponse([
                'message' => 'Você não possui permissão para acessar este recurso.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
