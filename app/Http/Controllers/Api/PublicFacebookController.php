<?php

namespace App\Http\Controllers\Api;

use App\Models\Business;
use App\Models\FacebookSetting;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class PublicFacebookController extends BaseController
{
    /**
     * @OA\Post(
     *     path="/api/public/businesses/{id}/facebook-settings/connect",
     *     tags={"Public"},
     *     summary="Connect Facebook account via OAuth",
     *     description="Exchange OAuth authorization code for access token and store Facebook settings (no authentication required)",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Business ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"code"},
     *             @OA\Property(property="code", type="string", example="oauth_authorization_code_from_facebook")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Facebook account connected successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Facebook account connected successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="business_id", type="integer", example=1),
     *                 @OA\Property(property="app_id", type="string", example="123456789"),
     *                 @OA\Property(property="status", type="string", example="active"),
     *                 @OA\Property(property="connected_at", type="string", format="date-time", example="2025-01-01T00:00:00Z")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request - invalid code or failed to exchange"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Business not found"
     *     )
     * )
     */
    public function connect(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $business = Business::where('status', 'active')->find($id);
        if (!$business) {
            return $this->notFoundResponse('Business not found or is not active');
        }

        try {
            // Exchange code for access token
            $facebookAppId = env('FACEBOOK_APP_ID');
            $facebookAppSecret = env('FACEBOOK_APP_SECRET');
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3039');
            $redirectUri = "{$frontendUrl}/connect-facebook-callback/{$id}";

            $response = Http::get('https://graph.facebook.com/v18.0/oauth/access_token', [
                'client_id' => $facebookAppId,
                'client_secret' => $facebookAppSecret,
                'redirect_uri' => $redirectUri,
                'code' => $request->code,
            ]);

            if (!$response->successful()) {
                $errorData = $response->json();
                $errorMessage = $errorData['error']['message'] ?? 'Failed to exchange authorization code';
                return $this->errorResponse($errorMessage, $errorData, 400);
            }

            $data = $response->json();
            $accessToken = $data['access_token'];
            $tokenExpiresIn = $data['expires_in'] ?? null;

            // Get user info from Facebook
            $userResponse = Http::withToken($accessToken)->get('https://graph.facebook.com/v18.0/me', [
                'fields' => 'id,name,email',
            ]);

            $userData = $userResponse->successful() ? $userResponse->json() : null;

            // Get business accounts (optional)
            $businessesResponse = Http::withToken($accessToken)->get('https://graph.facebook.com/v18.0/me/businesses', [
                'fields' => 'id,name',
            ]);

            $businessesData = $businessesResponse->successful() ? $businessesResponse->json() : null;

            // Store or update Facebook settings
            $facebookSetting = FacebookSetting::updateOrCreate(
                ['business_id' => $business->id],
                [
                    'app_id' => $facebookAppId,
                    'access_token' => encrypt($accessToken),
                    'token_type' => 'user',
                    'token_expires_at' => $tokenExpiresIn ? now()->addSeconds($tokenExpiresIn) : null,
                    'status' => 'active',
                    'connected_at' => now(),
                    'last_verified_at' => now(),
                    'user_info' => $userData,
                    'metadata' => [
                        'businesses' => $businessesData,
                        'connected_via' => 'public_link',
                    ],
                ]
            );

            // Remove sensitive data from response
            $responseData = [
                'id' => $facebookSetting->id,
                'business_id' => $facebookSetting->business_id,
                'app_id' => $facebookSetting->app_id,
                'status' => $facebookSetting->status,
                'connected_at' => $facebookSetting->connected_at,
                'user_info' => $userData,
            ];

            return $this->successResponse($responseData, 'Facebook account connected successfully');
        } catch (\Exception $e) {
            \Log::error('Facebook connection error', [
                'business_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return $this->errorResponse(
                'Failed to connect Facebook account. Please try again.',
                ['details' => $e->getMessage()],
                500
            );
        }
    }
}

