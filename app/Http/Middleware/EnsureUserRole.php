<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to one of the given roles: `role:admin`.
 */
class EnsureUserRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($roles && ! in_array($user->role, $roles, true)) {
            return response()->json([
                'success' => false,
                'message' => 'This action requires one of the following roles: ' . implode(', ', $roles) . '.',
            ], 403);
        }

        return $next($request);
    }
}
