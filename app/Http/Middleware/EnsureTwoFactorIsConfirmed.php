<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Google Authenticator is mandatory: a user who has not finished the enrolment
 * can only reach the 2FA setup endpoints until it is confirmed.
 */
class EnsureTwoFactorIsConfirmed
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->hasTwoFactorEnabled()) {
            return response()->json([
                'success' => false,
                'message' => 'Two-factor authentication must be configured before using the application.',
                'code' => 'two_factor_setup_required',
            ], 403);
        }

        return $next($request);
    }
}
