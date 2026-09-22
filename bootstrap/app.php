<?php

use App\Http\Middleware\AuthenticateApplication;
use App\Http\Middleware\EnsureTwoFactorIsConfirmed;
use App\Http\Middleware\EnsureUserRole;
use App\Http\Middleware\RecordActivity;
use App\Http\Middleware\TrustProxies;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies as FrameworkTrustProxies;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // TLS ends at Traefik: X-Forwarded-* are believed only from the proxies
        // of config/trustedproxy.php (TRUSTED_PROXIES), read on every request.
        // Not trustProxies(at: ...): this runs before the configuration is
        // loaded, so it would take a hard-coded list or env() outside config/.
        $middleware->replace(FrameworkTrustProxies::class, TrustProxies::class);

        $middleware->alias([
            'auth.app' => AuthenticateApplication::class,
            'role' => EnsureUserRole::class,
            '2fa' => EnsureTwoFactorIsConfirmed::class,
            'activity' => RecordActivity::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The API is stateless: never try to redirect to a login route.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            return null;
        });
    })->create();
