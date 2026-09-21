<?php

use App\Http\Middleware\EnsureAgentScope;
use App\Http\Middleware\EnsureAgentToken;
use App\Http\Middleware\EnsurePermission;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Em produção o TLS termina na Cloudflare e o cloudflared entrega a
        // requisição pelo loopback: sem confiar nele, o Laravel trataria tudo
        // como HTTP e os cookies Secure / URLs geradas quebrariam.
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);

        $middleware->statefulApi();

        // Tudo é servido na MESMA origem, então o statefulApi() aplicaria CSRF
        // aos POST públicos anônimos (agendamento/portal) e eles cairiam em 419.
        // A isenção fica restrita ao prefixo público, que não usa sessão.
        $middleware->validateCsrfTokens(except: ['api/v1/public/*']);

        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => $request->is('api/*') ? null : '/',
        );
        $middleware->alias(['permission' => EnsurePermission::class, 'agent.token' => EnsureAgentToken::class, 'agent.scope' => EnsureAgentScope::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
