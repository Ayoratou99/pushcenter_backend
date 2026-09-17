<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Api\BaseController;

class AuthController extends BaseController
{
    /**
     * @OA\Post(
     *     path="/api/v1/auth/login",
     *     tags={"Authentication"},
     *     summary="Login with username and password",
     *     description="Authenticate user with Keycloak and return access token",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"username", "password"},
     *             @OA\Property(property="username", type="string", example="admin"),
     *             @OA\Property(property="password", type="string", format="password", example="password123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful authentication",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Login successful"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="access_token", type="string", example="eyJhbGciOiJSUzI1NiIsInR5cCI..."),
     *                 @OA\Property(property="refresh_token", type="string", example="eyJhbGciOiJIUzI1NiIsInR5cCI..."),
     *                 @OA\Property(property="expires_in", type="integer", example=3600),
     *                 @OA\Property(property="refresh_expires_in", type="integer", example=36000),
     *                 @OA\Property(property="token_type", type="string", example="Bearer")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Invalid credentials",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Invalid username or password"),
     *             @OA\Property(property="error", type="string", example="invalid_grant")
     *         )
     *     ),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        try {
            // Keycloak token endpoint
            $keycloakUrl = env('KEYCLOAK_SERVER_URL', 'http://localhost:8080');
            $realm = env('KEYCLOAK_REALM', 'master');
            $clientId = env('KEYCLOAK_CLIENT_ID', 'aninfpush-client');
            $clientSecret = env('KEYCLOAK_CLIENT_SECRET', '');

            $tokenUrl = "{$keycloakUrl}/realms/{$realm}/protocol/openid-connect/token";

            // Request token from Keycloak
            $response = Http::asForm()->post($tokenUrl, [
                'grant_type' => 'password',
                'client_id' =>$clientId,
                'client_secret' => $clientSecret,
                'username' => $request->username,
                'password' => $request->password,
            ]);

            if ($response->failed()) {
                $error = $response->json();
                return $this->errorResponse(
                    $error['error_description'] ?? 'Invalid username or password',
                    $error,
                    401
                );
            }

            $tokenData = $response->json();

            return $this->successResponse([
                'access_token' => $tokenData['access_token'],
                'refresh_token' => $tokenData['refresh_token'],
                'expires_in' => $tokenData['expires_in'],
                'refresh_expires_in' => $tokenData['refresh_expires_in'],
                'token_type' => $tokenData['token_type'] ?? 'Bearer',
            ], 'Login successful');

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Authentication failed: ' . $e->getMessage(),
                null,
                500
            );
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/auth/refresh",
     *     tags={"Authentication"},
     *     summary="Refresh access token",
     *     description="Use refresh token to get a new access token",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"refresh_token"},
     *             @OA\Property(property="refresh_token", type="string", example="eyJhbGciOiJIUzI1NiIsInR5cCI...")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Token refreshed successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Token refreshed successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="access_token", type="string", example="eyJhbGciOiJSUzI1NiIsInR5cCI..."),
     *                 @OA\Property(property="refresh_token", type="string", example="eyJhbGciOiJIUzI1NiIsInR5cCI..."),
     *                 @OA\Property(property="expires_in", type="integer", example=3600),
     *                 @OA\Property(property="refresh_expires_in", type="integer", example=36000),
     *                 @OA\Property(property="token_type", type="string", example="Bearer")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Invalid refresh token")
     * )
     */
    public function refresh(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'refresh_token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        try {
            // Keycloak token endpoint
            $keycloakUrl = env('KEYCLOAK_SERVER_URL', 'http://localhost:8080');
            $realm = env('KEYCLOAK_REALM', 'master');
            $clientId = env('KEYCLOAK_CLIENT_ID', 'aninfpush-client');
            $clientSecret = env('KEYCLOAK_CLIENT_SECRET', '');

            $tokenUrl = "{$keycloakUrl}/realms/{$realm}/protocol/openid-connect/token";

            // Request new token using refresh token
            $response = Http::asForm()->post($tokenUrl, [
                'grant_type' => 'refresh_token',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $request->refresh_token,
            ]);

            if ($response->failed()) {
                $error = $response->json();
                return $this->errorResponse(
                    $error['error_description'] ?? 'Invalid refresh token',
                    $error,
                    401
                );
            }

            $tokenData = $response->json();

            return $this->successResponse([
                'access_token' => $tokenData['access_token'],
                'refresh_token' => $tokenData['refresh_token'],
                'expires_in' => $tokenData['expires_in'],
                'refresh_expires_in' => $tokenData['refresh_expires_in'],
                'token_type' => $tokenData['token_type'] ?? 'Bearer',
            ], 'Token refreshed successfully');

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Token refresh failed: ' . $e->getMessage(),
                null,
                500
            );
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/auth/logout",
     *     tags={"Authentication"},
     *     summary="Logout user",
     *     description="Revoke refresh token and invalidate session",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"refresh_token"},
     *             @OA\Property(property="refresh_token", type="string", example="eyJhbGciOiJIUzI1NiIsInR5cCI...")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Logout successful",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Logout successful")
     *         )
     *     )
     * )
     */
    public function logout(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'refresh_token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        try {
            // Keycloak logout endpoint
            $keycloakUrl = env('KEYCLOAK_SERVER_URL', 'http://localhost:8080');
            $realm = env('KEYCLOAK_REALM', 'master');
            $clientId = env('KEYCLOAK_CLIENT_ID', 'aninfpush-client');
            $clientSecret = env('KEYCLOAK_CLIENT_SECRET', '');

            $logoutUrl = "{$keycloakUrl}/realms/{$realm}/protocol/openid-connect/logout";

            // Revoke token
            Http::asForm()->post($logoutUrl, [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $request->refresh_token,
            ]);

            return $this->successResponse(null, 'Logout successful');

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Logout failed: ' . $e->getMessage(),
                null,
                500
            );
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/auth/user",
     *     tags={"Authentication"},
     *     summary="Get authenticated user info",
     *     description="Returns the current authenticated user information from Keycloak",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="User information retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="sub", type="string", example="uuid"),
     *                 @OA\Property(property="email_verified", type="boolean", example=true),
     *                 @OA\Property(property="name", type="string", example="John Doe"),
     *                 @OA\Property(property="preferred_username", type="string", example="john.doe"),
     *                 @OA\Property(property="given_name", type="string", example="John"),
     *                 @OA\Property(property="family_name", type="string", example="Doe"),
     *                 @OA\Property(property="email", type="string", example="john.doe@example.com")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function user(Request $request): JsonResponse
    {
        try {
            // Get token from Authorization header
            $token = $request->bearerToken();

            if (!$token) {
                return $this->errorResponse('No token provided', null, 401);
            }

            // Keycloak userinfo endpoint
            $keycloakUrl = env('KEYCLOAK_SERVER_URL', 'http://localhost:8080');
            $realm = env('KEYCLOAK_REALM', 'master');
            $userInfoUrl = "{$keycloakUrl}/realms/{$realm}/protocol/openid-connect/userinfo";

            // Get user info from Keycloak
            $response = Http::withToken($token)->get($userInfoUrl);

            if ($response->failed()) {
                return $this->errorResponse('Invalid or expired token', null, 401);
            }

            return $this->successResponse($response->json());

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to get user info: ' . $e->getMessage(),
                null,
                500
            );
        }
    }
}

