<?php

namespace App\Http\Middleware;

use App\Models\Business;
use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a machine caller holding a token obtained from
 * POST /api/v1/auth/token with its app_id and app_secret.
 *
 * The resolved application is bound to the request, so controllers never take
 * the business id from the payload: a token can only ever act on its own
 * application.
 */
class AuthenticateApplication
{
    public const ATTRIBUTE = 'application_business';

    public function __construct(private JwtService $jwt)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return $this->unauthenticated('No application token provided.');
        }

        $claims = $this->jwt->decode($token);

        if (! $claims || ($claims['token_type'] ?? null) !== JwtService::TYPE_APPLICATION) {
            return $this->unauthenticated('Invalid or expired application token.');
        }

        $business = Business::find($claims['business_id'] ?? $claims['sub'] ?? null);

        if (! $business) {
            return $this->unauthenticated('This application no longer exists.');
        }

        if (! $business->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'This application is not active.',
            ], 403);
        }

        $request->attributes->set(self::ATTRIBUTE, $business);

        return $next($request);
    }

    private function unauthenticated(string $message): Response
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 401);
    }
}
