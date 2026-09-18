<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Api\BaseController;
use App\Models\Business;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

/**
 * Client-credentials endpoint: an application exchanges its app_id and
 * app_secret for a short lived Bearer token.
 *
 * @OA\Tag(name="Application API", description="Machine-to-machine API")
 */
class AppTokenController extends BaseController
{
    private const MAX_ATTEMPTS = 10;

    private const LOCK_SECONDS = 300;

    public function __construct(private JwtService $jwt)
    {
    }

    /**
     * @OA\Post(
     *     path="/api/v1/auth/token",
     *     tags={"Application API"},
     *     summary="Exchange app credentials for an access token",
     *     description="Machine-to-machine authentication. The returned token is scoped to the owning application.",
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"app_id", "app_secret"},
     *         @OA\Property(property="app_id", type="string", example="app_1f2e3d..."),
     *         @OA\Property(property="app_secret", type="string", example="secret_9a8b7c...")
     *     )),
     *     @OA\Response(response=200, description="Access token"),
     *     @OA\Response(response=401, description="Invalid credentials"),
     *     @OA\Response(response=403, description="Application not active"),
     *     @OA\Response(response=429, description="Too many attempts")
     * )
     */
    public function issue(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'app_id' => 'required|string',
            'app_secret' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $throttleKey = 'app-token:' . $request->input('app_id') . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            return $this->errorResponse(
                'Too many token requests. Please retry in ' . RateLimiter::availableIn($throttleKey) . ' seconds.',
                null,
                429
            );
        }

        $business = Business::findByAppId($request->input('app_id'));

        // Same answer for an unknown app_id and a wrong secret, so the endpoint
        // cannot be used to discover which applications exist.
        if (! $business || ! $business->checkAppSecret($request->input('app_secret'))) {
            RateLimiter::hit($throttleKey, self::LOCK_SECONDS);

            return $this->errorResponse('Invalid application credentials', null, 401);
        }

        if (! $business->isActive()) {
            return $this->errorResponse('This application is not active.', null, 403);
        }

        RateLimiter::clear($throttleKey);

        return $this->successResponse(
            $this->jwt->applicationTokenPayload($business),
            'Access token issued'
        );
    }
}
