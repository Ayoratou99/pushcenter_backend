<?php

namespace App\Http\Services;

use App\Models\Business;
use App\Models\FacebookCredentials;
use App\Models\WhatsAppPhoneNumber;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppMessage;
use App\Http\Services\WhatsAppMediaService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\WabaAccount;

class FacebookMultiTenantService
{
    protected string $baseUrl;
    protected WhatsAppMediaService $mediaService;

    public function __construct(WhatsAppMediaService $mediaService)
    {
        $this->baseUrl = config('services.whatsapp.base_url', 'https://graph.facebook.com/v24.0');
        $this->mediaService = $mediaService;
    }

    /**
     * Get Facebook credentials for a business
     */
    protected function getCredentials(Business $business): ?FacebookCredentials
    {
        $credentials = $business->facebookCredentials()->active()->latest()->first();

        if (!$credentials) {
            Log::warning('No active Facebook credentials found for business', [
                'business_id' => $business->id
            ]);
            return null;
        }

        if (!$credentials->isActive()) {
            Log::warning('Facebook credentials are not active', [
                'business_id' => $business->id,
                'credentials_id' => $credentials->id,
                'status' => $credentials->status
            ]);
            return null;
        }

        return $credentials;
    }

    /**
     * Store/update Facebook credentials from embedded signup
     */
    public function storeCredentials(Business $business, array $data): FacebookCredentials
    {
        $credentials = FacebookCredentials::updateOrCreate(
            ['business_id' => $business->id],
            [
                'meta_business_id' => $data['meta_business_id'] ?? null,
                'app_id' => $data['app_id'],
                'deleted_at' => null,
                'access_token' => $data['access_token'],
                'token_type' => $data['token_type'] ?? 'user',
                'token_expires_at' => $data['token_expires_at'] ?? null,
                'app_secret' => $data['app_secret'] ?? config('services.facebook.client_secret'),
                'webhook_verify_token' => $data['webhook_verify_token'] ?? config('services.facebook.webhook_verify_token'),
                'status' => 'active',
                'connected_at' => now(),
                'last_verified_at' => now(),
                'granted_permissions' => $data['granted_permissions'] ?? [],
                'metadata' => $data['metadata'] ?? [],
                'user_info' => $data['user_info'] ?? [],
            ]
        );

        Log::info('Facebook credentials stored for business', [
            'business_id' => $business->id,
            'credentials_id' => $credentials->id
        ]);

        return $credentials;
    }

    /**
     * Revoke Facebook access token
     */
    public function revokeToken(string $accessToken): bool
    {
        try {
            Log::info('Attempting to revoke Facebook access token');

            // According to Facebook API docs, revoke permissions using DELETE
            // https://developers.facebook.com/docs/facebook-login/guides/access-tokens/expiration-and-extension#revoking
            $response = Http::delete("{$this->baseUrl}/me/permissions", [
                'access_token' => $accessToken
            ]);

            if ($response->successful()) {
                Log::info('Successfully revoked Facebook access token');
                return true;
            } else {
                Log::warning('Failed to revoke Facebook access token', [
                    'status' => $response->status(),
                    'response' => $response->json()
                ]);
                return false;
            }
        } catch (\Exception $e) {
            Log::error('Exception while revoking Facebook access token', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Update phone number profile information
     */
    public function updatePhoneNumberProfile(Business $business, string $phoneNumberId, array $data): array
    {
        Log::info('Facebook profile update started', [
            'business_id' => $business->id,
            'phone_number_id' => $phoneNumberId,
            'data_keys' => array_keys($data)
        ]);

        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            Log::error('No Facebook credentials found for business', ['business_id' => $business->id]);
            return [
                'success' => false,
                'error' => 'No active Facebook credentials found'
            ];
        }

        try {
            // Prepare data for Facebook API according to official documentation
            $facebookData = [];

            // Basic profile information
            if (isset($data['about'])) {
                $facebookData['about'] = $data['about'];
            }

            if (isset($data['description'])) {
                $facebookData['description'] = $data['description'];
            }

            if (isset($data['address'])) {
                $facebookData['address'] = $data['address'];
            }

            if (isset($data['email'])) {
                $facebookData['email'] = $data['email'];
            }

            if (isset($data['websites']) && is_array($data['websites'])) {
                // Filter out null values and ensure max 2 websites
                $websites = array_filter($data['websites'], function ($site) {
                    return !empty($site) && $site !== null;
                });
                if (!empty($websites)) {
                    $facebookData['websites'] = array_slice($websites, 0, 2);
                }
            }

            if (isset($data['vertical'])) {
                $facebookData['vertical'] = $data['vertical'];
            }

            // Profile picture update using Resumable Upload API (if provided)
            if (isset($data['profile_picture_file'])) {
                try {
                    $profilePictureHandle = $this->uploadProfilePicture($credentials, $data['profile_picture_file']);
                    if ($profilePictureHandle) {
                        $facebookData['profile_picture_handle'] = $profilePictureHandle;
                        Log::info('Profile picture handle prepared for Facebook', [
                            'phone_number_id' => $phoneNumberId,
                            'handle' => $profilePictureHandle
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error('Profile picture upload failed', [
                        'phone_number_id' => $phoneNumberId,
                        'error' => $e->getMessage()
                    ]);
                    return [
                        'success' => false,
                        'error' => 'Failed to upload profile picture: ' . $e->getMessage()
                    ];
                }
            }

            Log::info('Prepared Facebook data', [
                'phone_number_id' => $phoneNumberId,
                'facebook_data' => $facebookData
            ]);

            // According to Facebook's official documentation:
            // https://developers.facebook.com/docs/graph-api/reference/whats-app-business-account-to-number-current-status/whatsapp_business_profile/
            // Profile updates should be sent to the whatsapp_business_profile endpoint
            $facebookData['messaging_product'] = 'whatsapp'; // Required field

            $response = Http::withToken($credentials->access_token)
                ->post("https://graph.facebook.com/v24.0/{$phoneNumberId}/whatsapp_business_profile", $facebookData);

            Log::info('Facebook API response', [
                'phone_number_id' => $phoneNumberId,
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            if ($response->failed()) {
                $error = 'Failed to update phone number profile: ' . $response->body();
                Log::error('Facebook profile update failed', [
                    'phone_number_id' => $phoneNumberId,
                    'status' => $response->status(),
                    'error' => $response->body()
                ]);

                $credentials->recordError($error);

                return [
                    'success' => false,
                    'error' => $error,
                    'response' => $response->json()
                ];
            }

            $responseData = $response->json();
            Log::info('Facebook profile update successful', [
                'phone_number_id' => $phoneNumberId,
                'response' => $responseData
            ]);

            return [
                'success' => true,
                'data' => $responseData
            ];
        } catch (\Exception $e) {
            $error = 'Exception updating phone number profile: ' . $e->getMessage();
            Log::error('Facebook profile update exception', [
                'phone_number_id' => $phoneNumberId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $credentials->recordError($error);

            return [
                'success' => false,
                'error' => $error
            ];
        }
    }

    /**
     * Update WABA account profile information
     * According to Facebook documentation, profile fields like about, description, email
     * should be updated on the WABA account, not the phone number
     */
    public function updateWabaAccountProfile(Business $business, string $wabaAccountId, array $data): array
    {
        Log::info('WABA account profile update started', [
            'business_id' => $business->id,
            'waba_account_id' => $wabaAccountId,
            'data_keys' => array_keys($data)
        ]);

        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            Log::error('No Facebook credentials found for business', ['business_id' => $business->id]);
            return [
                'success' => false,
                'error' => 'No active Facebook credentials found'
            ];
        }

        try {
            // Prepare data for WABA account update
            $wabaData = [];

            if (isset($data['about'])) {
                $wabaData['about'] = $data['about'];
            }

            if (isset($data['description'])) {
                $wabaData['description'] = $data['description'];
            }

            if (isset($data['email'])) {
                $wabaData['email'] = $data['email'];
            }

            if (isset($data['address'])) {
                $wabaData['address'] = $data['address'];
            }

            if (isset($data['websites']) && is_array($data['websites'])) {
                $websites = array_filter($data['websites'], function ($site) {
                    return !empty($site) && $site !== null;
                });
                if (!empty($websites)) {
                    $wabaData['websites'] = array_slice($websites, 0, 2);
                }
            }

            if (isset($data['vertical'])) {
                $wabaData['vertical'] = $data['vertical'];
            }

            Log::info('Prepared WABA account data', [
                'waba_account_id' => $wabaAccountId,
                'waba_data' => $wabaData
            ]);

            // Update WABA account profile using Facebook Graph API
            $response = Http::withToken($credentials->access_token)
                ->post("https://graph.facebook.com/v24.0/{$wabaAccountId}", $wabaData);

            Log::info('WABA account Facebook API response', [
                'waba_account_id' => $wabaAccountId,
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            if ($response->failed()) {
                $error = 'Failed to update WABA account profile: ' . $response->body();
                Log::error('WABA account profile update failed', [
                    'waba_account_id' => $wabaAccountId,
                    'status' => $response->status(),
                    'error' => $response->body()
                ]);

                $credentials->recordError($error);

                return [
                    'success' => false,
                    'error' => $error,
                    'response' => $response->json()
                ];
            }

            $responseData = $response->json();
            Log::info('WABA account profile update successful', [
                'waba_account_id' => $wabaAccountId,
                'response' => $responseData
            ]);

            return [
                'success' => true,
                'data' => $responseData
            ];
        } catch (\Exception $e) {
            $error = 'Exception updating WABA account profile: ' . $e->getMessage();
            Log::error('WABA account profile update exception', [
                'waba_account_id' => $wabaAccountId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $credentials->recordError($error);

            return [
                'success' => false,
                'error' => $error
            ];
        }
    }

    /**
     * Sync single phone number from Facebook
     */
    public function syncSinglePhoneNumber(Business $business, WhatsAppPhoneNumber $phoneNumber): array
    {
        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            return ['success' => false, 'error' => 'No active Facebook credentials found'];
        }

        try {
            // According to Facebook's official documentation:
            // https://developers.facebook.com/docs/graph-api/reference/whats-app-business-account-to-number-current-status/
            // Complete phone number fields including messaging limits
            $fields = implode(',', [
                'id',
                'display_phone_number',
                'verified_name',
                'code_verification_status',
                'quality_rating',
                'name_status',
                'new_name_status',
                'status',
                'messaging_limit_tier',
                'throughput',
                'is_official_business_account',
                'is_pin_enabled',
                'account_mode',
                'certificate',
                'platform_type',
                'search_visibility',
                'webhook_configuration'
            ]);

            $response = Http::withToken($credentials->access_token)
                ->get("{$this->baseUrl}/{$phoneNumber->phone_number_id}", ['fields' => $fields]);

            if ($response->failed()) {
                $error = 'Failed to fetch phone number details: ' . $response->body();
                $credentials->recordError($error);
                return ['success' => false, 'error' => $error];
            }

            $data = $response->json();

            Log::info('Facebook phone number data retrieved', [
                'phone_number_id' => $phoneNumber->phone_number_id,
                'retrieved_fields' => array_keys($data),
                'messaging_limit_tier' => $data['messaging_limit_tier'] ?? 'NOT_FOUND',
                'quality_rating' => $data['quality_rating'] ?? 'NOT_FOUND',
                'status' => $data['status'] ?? 'NOT_FOUND',
                'throughput' => $data['throughput'] ?? 'NOT_FOUND'
            ]);

            // Now fetch the business profile information using the correct endpoint
            // According to: https://developers.facebook.com/docs/whatsapp/cloud-api/reference/business-profiles/?locale=fr_FR
            // The endpoint REQUIRES the 'fields' parameter to specify which profile fields to retrieve
            try {
                $profileResponse = Http::withToken($credentials->access_token)
                    ->get("https://graph.facebook.com/v24.0/{$phoneNumber->phone_number_id}/whatsapp_business_profile", [
                        'fields' => 'about,address,description,email,profile_picture_handle,websites,vertical'
                    ]);

                if ($profileResponse->successful()) {
                    $profileData = $profileResponse->json();
                    Log::info('Raw profile response from Facebook', [
                        'phone_number_id' => $phoneNumber->phone_number_id,
                        'profile_response' => $profileData
                    ]);

                    // According to Facebook documentation, the profile endpoint returns fields in a 'data' array
                    // We need to extract the profile information from the first item in the data array
                    if (isset($profileData['data']) && is_array($profileData['data']) && !empty($profileData['data'])) {
                        $profileInfo = $profileData['data'][0];



                        // Check if we have actual profile fields (not just messaging_product)
                        $hasProfileFields = isset($profileInfo['about']) || isset($profileInfo['description']) ||
                            isset($profileInfo['address']) || isset($profileInfo['email']) ||
                            isset($profileInfo['websites']) || isset($profileInfo['vertical']) ||
                            isset($profileInfo['profile_picture_url']) || isset($profileInfo['profile_picture_handle']) ||
                            isset($profileInfo['picture']) || isset($profileInfo['picture_url']);

                        if ($hasProfileFields) {
                            // Log which image field was found
                            if (isset($profileInfo['profile_picture_url'])) {
                                Log::info('Profile picture field found and normalized', [
                                    'phone_number_id' => $phoneNumber->phone_number_id,
                                    'image_url' => $profileInfo['profile_picture_url'],
                                    'original_fields' => array_keys($profileInfo)
                                ]);
                            }

                            // Merge profile data with basic phone data
                            $data = array_merge($data, $profileInfo);
                            Log::info('Profile data fetched and merged successfully', [
                                'phone_number_id' => $phoneNumber->phone_number_id,
                                'profile_fields' => array_keys($profileInfo),
                                'merged_fields' => array_keys($data)
                            ]);
                        } else {
                            Log::info('Profile endpoint returned data but no profile fields found', [
                                'phone_number_id' => $phoneNumber->phone_number_id,
                                'profile_data' => $profileInfo,
                                'note' => 'Only messaging_product found - this phone number may not have a configured business profile'
                            ]);

                            // Note: According to Facebook documentation, if we only get messaging_product,
                            // it means the phone number doesn't have a configured business profile yet.
                            // The fields parameter should have returned the profile data if it existed.
                            Log::info('No profile fields found - phone number may not have a configured business profile', [
                                'phone_number_id' => $phoneNumber->phone_number_id,
                                'note' => 'This is normal for new or unconfigured phone numbers'
                            ]);
                        }
                    } else {
                        Log::info('No profile data array found in response', [
                            'phone_number_id' => $phoneNumber->phone_number_id,
                            'profile_response' => $profileData
                        ]);
                    }
                } else {
                    Log::warning('Failed to fetch profile data', [
                        'phone_number_id' => $phoneNumber->phone_number_id,
                        'status' => $profileResponse->status(),
                        'response' => $profileResponse->body()
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('Exception fetching profile data', [
                    'phone_number_id' => $phoneNumber->phone_number_id,
                    'error' => $e->getMessage()
                ]);
            }

            // Prepare update data with all available Facebook fields
            $updateData = [
                'verified_name' => $data['verified_name'] ?? null,
                'quality_rating' => $data['quality_rating'] ?? null,
                'verification_status' => strtolower($data['code_verification_status'] ?? 'unverified'),
                'name_status' => $data['name_status'] ?? null,
                'new_name_status' => $data['new_name_status'] ?? null,
                'status' => strtolower($data['status'] ?? 'unknown'),
                'messaging_limit_tier' => $data['messaging_limit_tier'] ?? 'TIER_1000',
                'is_official_business_account' => $data['is_official_business_account'] ?? false,
                'is_pin_enabled' => $data['is_pin_enabled'] ?? false,
                'account_mode' => $data['account_mode'] ?? null,
                'certificate' => $data['certificate'] ?? null,
                'platform_type' => $data['platform_type'] ?? null,
                'search_visibility' => $data['search_visibility'] ?? null,
                'throughput' => $data['throughput'] ?? null,
                'webhook_configuration' => $data['webhook_configuration'] ?? null,
                'facebook_data' => $data, // Full snapshot including profile data
                'last_synced_at' => now(),
            ];

            // Calculate messaging limit based on tier
            if (isset($data['messaging_limit_tier'])) {
                $updateData['messaging_limit'] = $this->getMessagingLimitFromTier($data['messaging_limit_tier']);
            }

            // Note: Profile fields like 'about', 'description', 'address', 'email', 
            // 'websites', 'vertical', 'profile_picture_url' are NOT available on 
            // WhatsAppBusinessPhoneNumber nodes according to Facebook documentation.
            // These fields are only available on WABA account nodes.

            // We only save the basic phone number data that Facebook provides
            // Profile information should be managed locally and sent to Facebook
            // via the updatePhoneNumberProfile method when updating profiles

            Log::info('Syncing phone number with Facebook data', [
                'phone_number_id' => $phoneNumber->phone_number_id,
                'data_fields' => array_keys($data),
                'update_fields' => array_keys($updateData),
                'messaging_limit_tier_from_fb' => $data['messaging_limit_tier'] ?? 'NOT_PROVIDED',
                'calculated_messaging_limit' => $updateData['messaging_limit'] ?? 'NOT_CALCULATED'
            ]);

            $phoneNumber->update($updateData);

            // Verify the update was successful
            $phoneNumber->refresh();
            Log::info('Phone number updated successfully', [
                'phone_number_id' => $phoneNumber->phone_number_id,
                'saved_messaging_limit_tier' => $phoneNumber->messaging_limit_tier,
                'saved_messaging_limit' => $phoneNumber->messaging_limit,
                'saved_quality_rating' => $phoneNumber->quality_rating,
                'saved_status' => $phoneNumber->status
            ]);

            return ['success' => true];
        } catch (\Exception $e) {
            $error = 'Exception syncing phone number: ' . $e->getMessage();
            $credentials->recordError($error);
            return ['success' => false, 'error' => $error];
        }
    }

    /**
     * Sync phone numbers from Facebook for a business
     */
    public function syncPhoneNumbers(Business $business): array
    {
        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            return [
                'success' => false,
                'error' => 'No active Facebook credentials found'
            ];
        }

        $wabaAccounts = $business->wabaAccounts;
        if ($wabaAccounts->isEmpty()) {
            return [
                'success' => false,
                'error' => 'No WABA accounts found for this business'
            ];
        }

        $syncedCount = 0;
        $errors = [];

        // Keep track of all Facebook phone number IDs for cleanup
        $allFacebookPhoneIds = [];

        foreach ($wabaAccounts as $wabaAccount) {
            try {
                Log::info('Syncing phone numbers for WABA account', [
                    'business_id' => $business->id,
                    'waba_id' => $wabaAccount->waba_id
                ]);

                // Get phone numbers from Facebook for this WABA account
                $phoneNumbersResponse = Http::withToken($credentials->access_token)
                    ->get("https://graph.facebook.com/v24.0/{$wabaAccount->waba_id}/phone_numbers", [
                        'fields' => implode(',', [
                            'id',
                            'display_phone_number',
                            'verified_name',
                            'code_verification_status',
                            'quality_rating',
                            'name_status',
                            'new_name_status',
                            'status',
                            'messaging_limit_tier',
                            'throughput',
                            'is_official_business_account',
                            'is_pin_enabled',
                            'account_mode',
                            'certificate',
                            'platform_type',
                            'search_visibility',
                            'webhook_configuration'
                        ])
                    ]);

                if ($phoneNumbersResponse->failed()) {
                    $error = "Failed to fetch phone numbers for WABA {$wabaAccount->waba_id}: " . $phoneNumbersResponse->body();
                    Log::error($error);
                    $errors[] = $error;
                    continue;
                }

                $phoneNumbersData = $phoneNumbersResponse->json();
                $facebookPhoneNumbers = $phoneNumbersData['data'] ?? [];

                Log::info('Facebook phone numbers retrieved', [
                    'waba_id' => $wabaAccount->waba_id,
                    'phone_numbers_count' => count($facebookPhoneNumbers),
                    'phone_numbers_ids' => array_column($facebookPhoneNumbers, 'id'),
                    'phone_numbers_display' => array_column($facebookPhoneNumbers, 'display_phone_number')
                ]);

                // Collect all Facebook phone number IDs
                foreach ($facebookPhoneNumbers as $fbPhoneData) {
                    $allFacebookPhoneIds[] = $fbPhoneData['id'];
                }

                // Sync each phone number from Facebook
                foreach ($facebookPhoneNumbers as $index => $fbPhoneData) {
                    Log::info('Processing phone number', [
                        'index' => $index + 1,
                        'total' => count($facebookPhoneNumbers),
                        'phone_number_id' => $fbPhoneData['id'] ?? 'unknown',
                        'display_phone_number' => $fbPhoneData['display_phone_number'] ?? 'unknown'
                    ]);

                    $result = $this->syncPhoneNumberFromFacebookData($business, $wabaAccount, $fbPhoneData);

                    if ($result['success']) {
                        $syncedCount++;
                        Log::info('Phone number synced successfully', [
                            'phone_number_id' => $fbPhoneData['id'],
                            'synced_count_so_far' => $syncedCount
                        ]);
                    } else {
                        $error = "Error syncing phone number {$fbPhoneData['id']}: " . ($result['error'] ?? 'Unknown error');
                        $errors[] = $error;
                        Log::error('Phone number sync failed', [
                            'phone_number_id' => $fbPhoneData['id'],
                            'error' => $result['error'] ?? 'Unknown error'
                        ]);
                    }
                }
            } catch (\Exception $e) {
                $errors[] = "Exception syncing phone numbers for WABA {$wabaAccount->waba_id}: " . $e->getMessage();
            }
        }

        // Soft delete phone numbers that no longer exist in Facebook
        $this->cleanupOrphanedPhoneNumbers($business, $allFacebookPhoneIds);

        if (!empty($errors)) {
            Log::warning('Phone numbers sync failed', [
                'business_id' => $business->id,
                'errors' => $errors
            ]);
        }

        return [
            'success' => empty($errors),
            'synced_count' => $syncedCount,
            'errors' => $errors,
            'cleanup_performed' => true,
            'facebook_phone_ids' => $allFacebookPhoneIds
        ];
    }



    public function syncWabaAccount(Business $business, string $wabaId): array
    {
        $wabaDetails = $this->getWabaAccountDetails($business, $wabaId);
        if (!$wabaDetails['success']) {
            Log::error('Failed to get WABA account details', [
                'business_id' => $business->id,
                'waba_id' => $wabaId,
                'error' => $wabaDetails['error']
            ]);
            return [
                'success' => false,
                'error' => 'Failed to get WABA account details: ' . $wabaDetails['error']
            ];
        }
        // If we reach here, we have access to this WABA
        $accountType = 'client';

        // ✅ FIX: Check if WABA was soft-deleted and restore it
        $existingWaba = \App\Models\WabaAccount::withTrashed()
            ->where('business_id', $business->id)
            ->where('waba_id', $wabaId)
            ->first();

        if ($existingWaba && $existingWaba->trashed()) {
            // Restore soft-deleted WABA
            $existingWaba->restore();
            $existingWaba->update([
                'name' => $wabaDetails['data']['name'] ?? 'Unknown WABA',
                'status' => strtolower($wabaDetails['data']['account_review_status'] ?? 'unknown'),
                'verification_status' => strtolower($wabaDetails['data']['account_review_status'] ?? 'unknown'),
                'business_verification_status' => strtolower($wabaDetails['data']['account_review_status'] ?? 'unknown'),
                'account_type' => $accountType,
                'facebook_data' => $wabaDetails['data'],
                'last_synced_at' => now(),
            ]);

            Log::info('Restored soft-deleted WABA account', [
                'business_id' => $business->id,
                'waba_id' => $wabaId
            ]);
        } else {
            // Create or update WABA
            \App\Models\WabaAccount::updateOrCreate(
                [
                    'business_id' => $business->id,
                    'waba_id' => $wabaId
                ],
                [
                    'name' => $wabaDetails['data']['name'] ?? 'Unknown WABA',
                    'status' => strtolower($wabaDetails['data']['account_review_status'] ?? 'unknown'),
                    'verification_status' => strtolower($wabaDetails['data']['account_review_status'] ?? 'unknown'),
                    'business_verification_status' => strtolower($wabaDetails['data']['account_review_status'] ?? 'unknown'),
                    'account_type' => $accountType,
                    'facebook_data' => $wabaDetails['data'],
                    'last_synced_at' => now(),
                ]
            );
        }
        return [
            'success' => true,
            'waba_account' => $wabaDetails['data']
        ];
    }
    /**
     * Sync WABA accounts from Facebook
     */
    public function syncWabaAccounts(Business $business, string $wabaId = null): array
    {
        $credentials = $this->getCredentials($business);
        Log::info('Syncing WABA accounts', ['business_id' => $business->id, 'credentials' => $credentials]);
        if (!$credentials) {
            return [
                'success' => false,
                'error' => 'No active Facebook credentials found'
            ];
        }

        try {

            if ($wabaId) {
                $wabaResult = $this->syncWabaAccount($business, $wabaId);
                $this->subscribeWabaToWebhooks($business, $wabaId);
                if (!$wabaResult['success']) {
                    return [
                        'success' => false,
                        'error' => 'Failed to sync WABA account: ' . $wabaResult['error']
                    ];
                }
            } else {
                $wabaAccounts = $business->wabaAccounts;
                if ($wabaAccounts->isEmpty()) {
                    return [
                        'success' => false,
                        'error' => 'No WABA accounts found for this business'
                    ];
                }
                foreach ($wabaAccounts as $wabaAccount) {
                    $wabaResult = $this->syncWabaAccount($business, $wabaAccount->waba_id);
                    $this->subscribeWabaToWebhooks($business, $wabaAccount->waba_id);
                    if (!$wabaResult['success']) {
                        return [
                            'success' => false,
                            'error' => 'Failed to sync WABA account: ' . $wabaResult['error']
                        ];
                    }
                }
            }


            // Now sync phone numbers for all saved WABA accounts
            $phoneNumbersResult = $this->syncPhoneNumbers($business);

            // After syncing phone numbers, sync templates and create utility templates
            Log::info('Phone numbers synced, now syncing templates', [
                'business_id' => $business->id
            ]);

            $templatesResult = $this->syncTemplates($business);

            return [
                'success' => true,
                'phone_numbers_synced' => $phoneNumbersResult['success'],
                'phone_numbers_count' => $phoneNumbersResult['synced_count'] ?? 0,
                'phone_numbers_errors' => $phoneNumbersResult['errors'] ?? [],
                'templates_synced' => $templatesResult['success'],
                'templates_count' => $templatesResult['synced_count'] ?? 0,
                'templates_errors' => $templatesResult['errors'] ?? [],
                'summary' => [
                    'phone_numbers_synced' => $phoneNumbersResult['synced_count'] ?? 0,
                    'phone_numbers_errors' => count($phoneNumbersResult['errors'] ?? []),
                    'templates_synced' => $templatesResult['synced_count'] ?? 0,
                    'templates_errors' => count($templatesResult['errors'] ?? [])
                ]
            ];
        } catch (\Exception $e) {
            Log::error('Failed to sync WABA accounts', [
                'business_id' => $business->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Exception syncing WABA accounts: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get detailed information about a specific WABA account
     */
    public function getWabaAccountDetails(Business $business, string $wabaId): array
    {
        try {
            $credentials = $this->getCredentials($business);
            if (!$credentials) {
                return [
                    'success' => false,
                    'error' => 'No active Facebook credentials found'
                ];
            }

            Log::info('Fetching WABA account details', [
                'business_id' => $business->id,
                'waba_id' => $wabaId
            ]);

            // Get detailed WABA information using the specific endpoint
            $response = Http::withToken($credentials->access_token)
                ->get("{$this->baseUrl}/{$wabaId}", [
                    'fields' => 'id,name,currency,timezone_id,message_template_namespace,account_review_status'
                ]);

            if ($response->failed()) {
                $error = 'Failed to fetch WABA account details: ' . $response->body();
                Log::error('WABA account details fetch failed', [
                    'business_id' => $business->id,
                    'waba_id' => $wabaId,
                    'response_status' => $response->status(),
                    'response_body' => $response->body()
                ]);

                return [
                    'success' => false,
                    'error' => $error
                ];
            }

            $wabaData = $response->json();

            Log::info('WABA account details fetched successfully', [
                'business_id' => $business->id,
                'waba_id' => $wabaId,
                'name' => $wabaData['name'] ?? 'Unknown'
            ]);

            return [
                'success' => true,
                'data' => $wabaData
            ];
        } catch (\Exception $e) {
            Log::error('Failed to fetch WABA account details', [
                'business_id' => $business->id,
                'waba_id' => $wabaId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get Nes_ite main business for platform operations (like consent OTP)
     */
    protected function getMainBusiness(): ?Business
    {
        return Business::where('is_main_platform', true)
            ->first();
    }

    /**
     * Send OTP for consent verification (uses AYOSPUSH credentials)
     */
    /**
     * Send OTP for verification using a specified template.
     */
    /*public function sendOtpForVerification(string $phoneNumber, string $templateName, string $cacheKeyPrefix): array
    {
        try {
            $mainBusiness = $this->getMainBusiness();
            if (!$mainBusiness) {
                return ['success' => false, 'error' => 'AYOSPUSH main platform business not found'];
            }

            $senderPhoneNumber = $mainBusiness->phoneNumbers()->where('verification_status', 'verified')->first();
            if (!$senderPhoneNumber) {
                return ['success' => false, 'error' => 'No verified phone number available for AYOSPUSH platform'];
            }

            $otpCode = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            \Illuminate\Support\Facades\Cache::put("{$cacheKeyPrefix}_{$phoneNumber}", $otpCode, 300); // 5 minutes = 300 seconds
            $cleanPhoneNumber = ltrim($phoneNumber, '+');

            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $cleanPhoneNumber,
                'type' => 'template',
                'template' => [
                    'name' => $templateName,
                    'language' => ['code' => 'fr'],
                    'components' => [
                        [
                            'type' => 'body',
                            'parameters' => [
                                ['type' => 'text', 'text' => $otpCode, "parameter_name" => "1"]
                            ]
                        ],
                        [
                            'type' => 'button',
                            ''
                            'parameters' => [
                                ['type' => 'text', 'text' => $otpCode, "parameter_name" => "1"]
                            ]
                        ],

                    ]
                ]
            ];

            Log::info('Sending OTP authentication template', [
                'phone_number' => $phoneNumber,
                'template_name' => $templateName,
                'otp_code' => $otpCode
            ]);

            $credentials = $this->getCredentials($mainBusiness);
            $response = Http::withToken($credentials->access_token)
                ->timeout(30)
                ->post("https://graph.facebook.com/v24.0/{$senderPhoneNumber->phone_number_id}/messages", $payload);

            if ($response->successful()) {
                $responseData = $response->json();
                Log::info('OTP authentication template sent successfully', [
                    'phone_number' => $phoneNumber,
                    'phonenumber_id' => $senderPhoneNumber->phone_number_id,
                    'message_id' => $responseData['messages'][0]['id'] ?? null
                ]);
                return ['success' => true, 'message' => 'OTP sent successfully'];
            } else {
                $errorBody = $response->json();
                Log::error('Failed to send OTP authentication template', [
                    'error' => $errorBody,
                    'phone_number' => $phoneNumber,
                    'phonenumber_id' => $senderPhoneNumber->phone_number_id,
                    'template_name' => $templateName
                ]);

                $errorMessage = $this->parseAuthenticationTemplateError($errorBody);
                return ['success' => false, 'error' => $errorMessage];
            }
        } catch (\Exception $e) {
            Log::error('Failed to send OTP verification', [
                'phone_number' => $phoneNumber,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }*/

    private function parseAuthenticationTemplateError(array $errorBody): string
    {
        $error = $errorBody['error'] ?? [];
        $message = $error['message'] ?? 'Unknown error';
        $code = $error['code'] ?? null;

        switch ($code) {
            case 100:
                return "Invalid parameter. Check: 1) Template name spelling, 2) Template is approved, 3) Language code is supported, 4) Parameter types are correct";
            case 131051:
                return "Template not found or not approved for authentication messages";
            case 131052:
                return "Template language not supported";
            case 131053:
                return "Template parameter mismatch";
            default:
                return $message;
        }
    }
    /**
     * Verify OTP code against the cached value.
     */
    public function verifyOtp(string $phoneNumber, string $otpCode, string $cacheKeyPrefix): array
    {
        try {
            $cachedOtp = \Illuminate\Support\Facades\Cache::get("{$cacheKeyPrefix}_{$phoneNumber}");

            if (!$cachedOtp) {
                return ['success' => false, 'error' => 'Code de vérification expiré ou introuvable'];
            }

            if ($cachedOtp === $otpCode) {
                \Illuminate\Support\Facades\Cache::forget("{$cacheKeyPrefix}_{$phoneNumber}");
                return ['success' => true, 'message' => 'Code de vérification confirmé'];
            } else {
                return ['success' => false, 'error' => 'Code de vérification incorrect'];
            }
        } catch (\Exception $e) {
            Log::error('Failed to verify OTP', ['phone_number' => $phoneNumber, 'error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send OTP for phone number verification (legacy method for business verification)
     */
    public function sendOtpVerification(string $phoneNumber, Business $business): array
    {
        try {
            // For consent process, use AYOSPUSH main credentials instead of business credentials
            $MainBusiness = $this->getMainBusiness();

            if (!$MainBusiness) {
                // Fallback to business credentials if AYOSPUSH business not found
                $MainBusiness = $business;
            }

            // Get verified phone number for sending from AYOSPUSH business
            $senderPhoneNumber = $MainBusiness->phoneNumbers()->where('verification_status', 'verified')->first();

            if (!$senderPhoneNumber) {
                return [
                    'success' => false,
                    'error' => 'No verified phone number available for AYOSPUSH platform'
                ];
            }

            $otpCode = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            // Store OTP in cache for 10 minutes
            \Illuminate\Support\Facades\Cache::put("otp_verification_{$phoneNumber}", $otpCode, 600);

            $message = "Votre code de vérification AYOSPUSH est: {$otpCode}. Ce code expire dans 10 minutes.";

            // Use AYOSPUSH credentials for sending OTP
            $result = $this->sendTextMessage($MainBusiness, $senderPhoneNumber->phone_number_id, $phoneNumber, $message);

            if ($result['success']) {
                return [
                    'success' => true,
                    'otp_code' => $otpCode, // Remove this in production
                    'message' => 'OTP sent successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to send OTP: ' . ($result['error'] ?? 'Unknown error')
                ];
            }
        } catch (\Exception $e) {
            Log::error('Failed to send OTP verification', [
                'phone_number' => $phoneNumber,
                'business_id' => $business->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Map Facebook verification status to our enum
     */
    private function mapVerificationStatus(string $fbStatus): string
    {
        return match ($fbStatus) {
            'VERIFIED' => 'verified',
            'UNVERIFIED' => 'unverified',
            'PENDING' => 'pending',
            'FAILED' => 'rejected',
            default => 'unverified'
        };
    }

    /**
     * Upload profile picture using Facebook Resumable Upload API
     * Based on official documentation: https://developers.facebook.com/docs/graph-api/guides/upload
     * 
     * @param FacebookCredentials $credentials
     * @param $file UploadedFile
     * @param bool $isTemplateMedia
     * @param string|null $forcedMimeType Optional MIME type to override auto-detection (fixes text/html issue)
     */
    protected function uploadProfilePicture(FacebookCredentials $credentials, $file, $isTemplateMedia = false, ?string $forcedMimeType = null): ?string
    {
        $appId = $credentials->app_id;
        $accessToken = $credentials->access_token;

        // Get file information
        $fileName = $file->getClientOriginalName();
        $fileSize = $file->getSize();

        // Use forced MIME type if provided (to avoid getMimeType() returning text/html for downloaded files)
        // Otherwise use auto-detection
        $mimeType = $forcedMimeType ?? $file->getMimeType();

        // Validate file type based on usage
        if ($isTemplateMedia) {
            // For templates: IMAGE, VIDEO, DOCUMENT allowed
            $allowedTypes = [
                'image/jpeg',
                'image/jpg',
                'image/png',
                'image/pjpeg',
                'image/x-png',
                'video/mp4',
                'video/3gpp',
                'video/quicktime',
                'application/pdf',
                'application/x-pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation'
            ];
            $maxSize = 16 * 1024 * 1024; // 16MB for template media
        } else {
            // For profile pictures: only images
            $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/pjpeg', 'image/x-png'];
            $maxSize = 5 * 1024 * 1024; // 5MB for profile pictures
        }

        // Normalize MIME type to lowercase for comparison
        $mimeTypeLower = strtolower($mimeType);
        $allowedTypesLower = array_map('strtolower', $allowedTypes);

        // Also check by file extension as fallback
        $fileName = $file->getClientOriginalName();
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'mp4', '3gp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];

        $isMimeTypeValid = in_array($mimeTypeLower, $allowedTypesLower);
        $isExtensionValid = $isTemplateMedia ? in_array($fileExtension, $allowedExtensions) : in_array($fileExtension, ['jpg', 'jpeg', 'png']);

        if (!$isMimeTypeValid && !$isExtensionValid) {
            Log::warning('File type validation failed', [
                'mime_type' => $mimeType,
                'mime_type_lower' => $mimeTypeLower,
                'file_name' => $fileName,
                'file_extension' => $fileExtension,
                'allowed_mime_types' => $allowedTypes,
                'is_template_media' => $isTemplateMedia
            ]);

            $errorMsg = $isTemplateMedia
                ? 'Invalid file type. Allowed: JPEG, PNG, MP4, PDF, DOC, XLS, PPT'
                : 'Invalid file type. Only JPEG and PNG are allowed.';
            throw new \Exception($errorMsg);
        }

        // Validate file size
        if ($fileSize > $maxSize) {
            $maxSizeMB = round($maxSize / (1024 * 1024));
            throw new \Exception("File size too large. Maximum {$maxSizeMB}MB allowed.");
        }

        Log::info($isTemplateMedia ? 'Starting template media upload' : 'Starting profile picture upload', [
            'app_id' => $appId,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'mime_type' => $mimeType,
            'mime_type_source' => $forcedMimeType ? 'forced' : 'auto-detected',
            'forced_mime_type' => $forcedMimeType,
            'is_template_media' => $isTemplateMedia
        ]);

        // Step 1: Start upload session
        $sessionResponse = Http::withToken($accessToken)
            ->post("https://graph.facebook.com/v24.0/{$appId}/uploads", [
                'file_name' => $fileName,
                'file_length' => $fileSize,
                'file_type' => $mimeType
            ]);

        if ($sessionResponse->failed()) {
            $errorBody = $sessionResponse->body();
            Log::error('Failed to start upload session', [
                'response' => $errorBody,
                'status' => $sessionResponse->status()
            ]);
            throw new \Exception('Failed to start upload session: ' . $errorBody);
        }

        $sessionData = $sessionResponse->json();
        $uploadSessionId = $sessionData['id'] ?? null;

        if (!$uploadSessionId) {
            Log::error('No upload session ID received', ['response' => $sessionData]);
            throw new \Exception('No upload session ID received from Facebook');
        }

        Log::info('Upload session created', [
            'session_id' => $uploadSessionId
        ]);

        // Step 2: Upload file data
        $fileContent = $file->getContent();

        $uploadResponse = Http::withHeaders([
            'Authorization' => 'OAuth ' . $accessToken,
            'file_offset' => '0',
            'Content-Type' => 'application/octet-stream'
        ])->withBody($fileContent, $mimeType)
            ->post("https://graph.facebook.com/v24.0/{$uploadSessionId}");

        if ($uploadResponse->failed()) {
            $errorBody = $uploadResponse->body();
            Log::error('Failed to upload file', [
                'response' => $errorBody,
                'status' => $uploadResponse->status()
            ]);
            throw new \Exception('Failed to upload file: ' . $errorBody);
        }

        $uploadData = $uploadResponse->json();
        $fileHandle = $uploadData['h'] ?? null;

        if (!$fileHandle) {
            Log::error('No file handle received', ['response' => $uploadData]);
            throw new \Exception('No file handle received from Facebook');
        }

        Log::info('File uploaded successfully', [
            'session_id' => $uploadSessionId,
            'file_handle' => $fileHandle
        ]);

        return $fileHandle;
    }

    /**
     * Upload media for template using Resumable Upload API
     * Uses the same API as profile picture upload
     * Returns file handle that can be used in template creation
     * 
     * @param Business $business
     * @param $file UploadedFile
     * @param string|null $forcedMimeType Optional MIME type to override auto-detection
     */
    public function uploadMediaForTemplate(Business $business, $file, ?string $forcedMimeType = null): array
    {
        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            return [
                'success' => false,
                'error' => 'No active Facebook credentials found'
            ];
        }

        try {
            // Use the same Resumable Upload API as profile pictures, but with template media flag
            // Pass forced MIME type to avoid getMimeType() returning text/html for downloaded files
            $fileHandle = $this->uploadProfilePicture($credentials, $file, true, $forcedMimeType);

            if ($fileHandle) {
                return [
                    'success' => true,
                    'file_handle' => $fileHandle
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to get file handle from upload'
                ];
            }
        } catch (\Exception $e) {
            Log::error('Template media upload failed', [
                'business_id' => $business->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Register phone number with Facebook WhatsApp Business API
     * According to: https://developers.facebook.com/docs/whatsapp/business-management-api/manage-phone-numbers
     */
    public function addPhoneNumberToWaba(Business $business, string $wabaId, string $fullPhoneNumber, string $verifiedName, array $profileData): array
    {
        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            return ['success' => false, 'error' => 'No active Facebook credentials found'];
        }

        try {
            // Clean the phone number: remove '+' and any non-digit characters
            $cleanedPhoneNumber = preg_replace('/\D/', '', $fullPhoneNumber);

            // Extract country code and national number
            // This is a simplified approach. For production, a library like libphonenumber is recommended.
            $cc = '';
            $nationalNumber = '';
            // Assuming country codes are 1 to 3 digits. This is a common simplification.
            if (strlen($cleanedPhoneNumber) > 10) {
                if (substr($cleanedPhoneNumber, 0, 3) == '241') { // Gabon
                    $cc = '241';
                    $nationalNumber = substr($cleanedPhoneNumber, 3);
                } else if (substr($cleanedPhoneNumber, 0, 3) == '242') { // Congo
                    $cc = '242';
                    $nationalNumber = substr($cleanedPhoneNumber, 3);
                } else if (substr($cleanedPhoneNumber, 0, 3) == '237') { // Cameroon
                    $cc = '237';
                    $nationalNumber = substr($cleanedPhoneNumber, 3);
                }
                // Add more country codes as needed for your region
                else {
                    // Fallback for other numbers, might not be accurate
                    $cc = substr($cleanedPhoneNumber, 0, strlen($cleanedPhoneNumber) - 9);
                    $nationalNumber = substr($cleanedPhoneNumber, -9);
                }
            } else {
                // Fallback for shorter numbers
                $nationalNumber = $cleanedPhoneNumber;
            }

            if (empty($cc)) {
                return ['success' => false, 'error' => 'Could not determine country code.'];
            }


            Log::info('Adding phone number to WABA on Facebook', [
                'business_id' => $business->id,
                'waba_id' => $wabaId,
                'full_phone_number' => $fullPhoneNumber,
                'parsed_cc' => $cc,
                'parsed_national_number' => $nationalNumber,
                'verified_name' => $verifiedName,
            ]);

            // Prepare the request payload with cc and phone_number
            $payload = array_merge($profileData, [
                'messaging_product' => 'whatsapp',
                'verified_name' => $verifiedName,
                'cc' => $cc,
                'phone_number' => $nationalNumber,
            ]);

            $response = Http::withToken($credentials->access_token)
                ->post("https://graph.facebook.com/v24.0/{$wabaId}/phone_numbers", $payload);

            if ($response->failed()) {
                $error = 'Failed to add phone number to WABA: ' . $response->body();
                Log::error($error, [
                    'business_id' => $business->id,
                    'waba_id' => $wabaId,
                    'response_status' => $response->status(),
                    'response_body' => $response->body()
                ]);

                return ['success' => false, 'error' => $error, 'facebook_error' => $response->json()];
            }

            $responseData = $response->json();

            Log::info('Phone number added to WABA successfully', [
                'business_id' => $business->id,
                'waba_id' => $wabaId,
                'response' => $responseData
            ]);

            return ['success' => true, 'data' => $responseData, 'message' => 'Phone number added to WABA successfully on Facebook'];
        } catch (\Exception $e) {
            Log::error('Adding phone number to WABA failed', [
                'business_id' => $business->id,
                'waba_id' => $wabaId,
                'error' => $e->getMessage()
            ]);

            return ['success' => false, 'error' => 'Failed to add number: ' . $e->getMessage()];
        }
    }

    /**
     * Check if phone number exists in Facebook WABA
     */
    public function checkPhoneNumberExists(Business $business, string $wabaId, string $phoneNumber): array
    {
        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            return [
                'success' => false,
                'error' => 'No active Facebook credentials found'
            ];
        }

        try {
            // Get all phone numbers for this WABA
            $response = Http::withToken($credentials->access_token)
                ->get("https://graph.facebook.com/v24.0/{$wabaId}/phone_numbers");

            if ($response->failed()) {
                return [
                    'success' => false,
                    'error' => 'Failed to check phone numbers: ' . $response->body()
                ];
            }

            $phoneNumbersData = $response->json();
            $existingNumbers = $phoneNumbersData['data'] ?? [];

            // Check if phone number already exists
            foreach ($existingNumbers as $existingNumber) {
                if (($existingNumber['display_phone_number'] ?? '') === $phoneNumber) {
                    return [
                        'success' => true,
                        'exists' => true,
                        'phone_data' => $existingNumber
                    ];
                }
            }

            return [
                'success' => true,
                'exists' => false
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get template status from Facebook
     */
    public function getTemplateStatus(Business $business, string $facebookTemplateId): array
    {
        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            return [
                'success' => false,
                'error' => 'No active Facebook credentials found'
            ];
        }

        try {
            Log::info('Getting template status from Facebook', [
                'business_id' => $business->id,
                'facebook_template_id' => $facebookTemplateId
            ]);

            $response = Http::withToken($credentials->access_token)
                ->get("https://graph.facebook.com/v24.0/{$facebookTemplateId}");

            if ($response->failed()) {
                $error = 'Failed to get template status: ' . $response->body();
                Log::error($error, [
                    'business_id' => $business->id,
                    'facebook_template_id' => $facebookTemplateId
                ]);

                return [
                    'success' => false,
                    'error' => $error
                ];
            }

            $templateData = $response->json();

            Log::info('Template status retrieved successfully', [
                'business_id' => $business->id,
                'facebook_template_id' => $facebookTemplateId,
                'status' => $templateData['status'] ?? 'unknown'
            ]);

            return [
                'success' => true,
                'data' => $templateData
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get template status', [
                'business_id' => $business->id,
                'facebook_template_id' => $facebookTemplateId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }


    /**
     * Sync phone number from Facebook data (used when getting phone numbers from WABA)
     */
    protected function syncPhoneNumberFromFacebookData(Business $business, $wabaAccount, array $fbPhoneData): array
    {
        try {
            Log::info('Syncing phone number from Facebook data', [
                'business_id' => $business->id,
                'waba_id' => $wabaAccount->waba_id,
                'phone_number_id' => $fbPhoneData['id'],
                'facebook_data_fields' => array_keys($fbPhoneData),
                'messaging_limit_tier' => $fbPhoneData['messaging_limit_tier'] ?? 'NOT_PROVIDED'
            ]);

            // ✅ FIX: Check if phone number was soft-deleted and restore it
            $existingPhone = \App\Models\WhatsAppPhoneNumber::withTrashed()
                ->where('business_id', $business->id)
                ->where('phone_number_id', $fbPhoneData['id'])
                ->first();

            if ($existingPhone && $existingPhone->trashed()) {
                // Restore soft-deleted phone number
                $existingPhone->restore();
                $phoneNumber = $existingPhone;
                $phoneNumber->update([
                    'waba_account_id' => $wabaAccount->id,
                    'phone_number' => $fbPhoneData['display_phone_number'] ?? 'UNKNOWN',
                    'display_phone_number' => $fbPhoneData['display_phone_number'] ?? null,
                    'verified_name' => $fbPhoneData['verified_name'] ?? null,
                    'quality_rating' => $fbPhoneData['quality_rating'] ?? null,
                    'verification_status' => $this->mapVerificationStatus($fbPhoneData['code_verification_status'] ?? 'UNVERIFIED'),
                    'name_status' => $fbPhoneData['name_status'] ?? null,
                    'new_name_status' => $fbPhoneData['new_name_status'] ?? null,
                    'status' => strtolower($fbPhoneData['status'] ?? 'unknown'),
                    'messaging_limit_tier' => $fbPhoneData['messaging_limit_tier'] ?? 'TIER_1000',
                    'messaging_limit' => $this->getMessagingLimitFromTier($fbPhoneData['messaging_limit_tier'] ?? 'TIER_1000'),
                    'is_official_business_account' => $fbPhoneData['is_official_business_account'] ?? false,
                    'is_pin_enabled' => $fbPhoneData['is_pin_enabled'] ?? false,
                    'account_mode' => $fbPhoneData['account_mode'] ?? null,
                    'certificate' => $fbPhoneData['certificate'] ?? null,
                    'platform_type' => $fbPhoneData['platform_type'] ?? null,
                    'search_visibility' => $fbPhoneData['search_visibility'] ?? null,
                    'throughput' => $fbPhoneData['throughput'] ?? null,
                    'webhook_configuration' => $fbPhoneData['webhook_configuration'] ?? null,
                    'facebook_data' => $fbPhoneData,
                    'last_synced_at' => now(),
                ]);

                Log::info('Restored soft-deleted phone number', [
                    'business_id' => $business->id,
                    'phone_number_id' => $fbPhoneData['id']
                ]);
            } else {
                // Find or create phone number record
                $phoneNumber = \App\Models\WhatsAppPhoneNumber::updateOrCreate(
                    [
                        'business_id' => $business->id,
                        'phone_number_id' => $fbPhoneData['id']
                    ],
                    [
                        'waba_account_id' => $wabaAccount->id,
                        'phone_number' => $fbPhoneData['display_phone_number'] ?? 'UNKNOWN',
                        'display_phone_number' => $fbPhoneData['display_phone_number'] ?? null,
                        'verified_name' => $fbPhoneData['verified_name'] ?? null,
                        'quality_rating' => $fbPhoneData['quality_rating'] ?? null,
                        'verification_status' => $this->mapVerificationStatus($fbPhoneData['code_verification_status'] ?? 'UNVERIFIED'),
                        'name_status' => $fbPhoneData['name_status'] ?? null,
                        'new_name_status' => $fbPhoneData['new_name_status'] ?? null,
                        'status' => strtolower($fbPhoneData['status'] ?? 'unknown'),
                        'messaging_limit_tier' => $fbPhoneData['messaging_limit_tier'] ?? 'TIER_1000',
                        'messaging_limit' => $this->getMessagingLimitFromTier($fbPhoneData['messaging_limit_tier'] ?? 'TIER_1000'),
                        'is_official_business_account' => $fbPhoneData['is_official_business_account'] ?? false,
                        'is_pin_enabled' => $fbPhoneData['is_pin_enabled'] ?? false,
                        'account_mode' => $fbPhoneData['account_mode'] ?? null,
                        'certificate' => $fbPhoneData['certificate'] ?? null,
                        'platform_type' => $fbPhoneData['platform_type'] ?? null,
                        'search_visibility' => $fbPhoneData['search_visibility'] ?? null,
                        'throughput' => $fbPhoneData['throughput'] ?? null,
                        'webhook_configuration' => $fbPhoneData['webhook_configuration'] ?? null,
                        'facebook_data' => $fbPhoneData,
                        'last_synced_at' => now(),
                    ]
                );
            }

            $this->registerPhoneNumber($business, $fbPhoneData['id'], "666666");

            Log::info('Phone number synced successfully from Facebook data', [
                'phone_number_id' => $fbPhoneData['id'],
                'messaging_limit_tier' => $phoneNumber->messaging_limit_tier,
                'messaging_limit' => $phoneNumber->messaging_limit,
                'quality_rating' => $phoneNumber->quality_rating,
                'status' => $phoneNumber->status
            ]);

            // Note: Webhook subscription is done at WABA level, not individual phone numbers
            // This will be handled after all phone numbers are synced

            return ['success' => true, 'phone_number' => $phoneNumber];
        } catch (\Exception $e) {
            Log::error('Failed to sync phone number from Facebook data', [
                'business_id' => $business->id,
                'phone_number_id' => $fbPhoneData['id'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }


    public function registerPhoneNumber(Business $business, string $phoneNumberId, string $pin): array
    {
        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            return ['success' => false, 'error' => 'No active Facebook credentials found'];
        }

        try {
            Log::info('Registering phone number with Facebook via official endpoint', [
                'business_id' => $business->id,
                'phone_number_id' => $phoneNumberId,
                'pin' => $pin
            ]);

            // CORRECT ENDPOINT based on official documentation
            $response = Http::withToken($credentials->access_token)
                ->post("https://graph.facebook.com/v24.0/{$phoneNumberId}/register", [
                    'messaging_product' => 'whatsapp',
                    'pin' => $pin
                ]);

            if ($response->failed()) {
                $errorBody = $response->json();
                Log::error('Failed to register phone number via official endpoint', [
                    'phone_number_id' => $phoneNumberId,
                    'response_status' => $response->status(),
                    'response_body' => $response->body(),
                    'facebook_error' => $errorBody
                ]);

                $errorMessage = $errorBody['error']['message'] ?? 'Failed to register phone number';
                return ['success' => false, 'error' => $errorMessage];
            }

            $responseData = $response->json();
            Log::info('Phone number registered successfully via official endpoint', [
                'phone_number_id' => $phoneNumberId,
                'response' => $responseData
            ]);

            return ['success' => true, 'data' => $responseData];
        } catch (\Exception $e) {
            Log::error('Exception registering phone number via official endpoint', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get messaging limit from tier according to Facebook documentation
     */
    protected function getMessagingLimitFromTier(string $tier): array
    {
        // According to Facebook documentation: https://developers.facebook.com/docs/whatsapp/messaging-limits
        $limits = [
            'TIER_50' => ['tier' => 'TIER_50', 'limit' => 50],
            'TIER_250' => ['tier' => 'TIER_250', 'limit' => 250],
            'TIER_1K' => ['tier' => 'TIER_1K', 'limit' => 1000],
            'TIER_1000' => ['tier' => 'TIER_1000', 'limit' => 1000], // Alternative naming
            'TIER_5K' => ['tier' => 'TIER_5K', 'limit' => 5000],
            'TIER_5000' => ['tier' => 'TIER_5000', 'limit' => 5000], // Alternative naming
            'TIER_10K' => ['tier' => 'TIER_10K', 'limit' => 10000],
            'TIER_10000' => ['tier' => 'TIER_10000', 'limit' => 10000], // Alternative naming
            'TIER_25K' => ['tier' => 'TIER_25K', 'limit' => 25000],
            'TIER_25000' => ['tier' => 'TIER_25000', 'limit' => 25000], // Alternative naming
            'TIER_50K' => ['tier' => 'TIER_50K', 'limit' => 50000],
            'TIER_50000' => ['tier' => 'TIER_50000', 'limit' => 50000], // Alternative naming
            'TIER_100K' => ['tier' => 'TIER_100K', 'limit' => 100000],
            'TIER_100000' => ['tier' => 'TIER_100000', 'limit' => 100000], // Alternative naming
            'TIER_UNLIMITED' => ['tier' => 'TIER_UNLIMITED', 'limit' => PHP_INT_MAX],
        ];

        return $limits[$tier] ?? ['tier' => 'TIER_250', 'limit' => 250]; // Default fallback
    }

    public function formatWaId(string $waId): ?string
    {
        // Remove all non-digit characters
        $cleanNumber = preg_replace('/[^0-9]/', '', $waId);

        if (empty($cleanNumber)) {
            return null;
        }

        $hasCountryCode = str_starts_with($cleanNumber, '241');

        // If number doesn't have country code, we'll handle it differently
        if (!$hasCountryCode) {
            return $this->formatLocalGabonNumber($cleanNumber);
        }

        // Number has country code, process it
        $numberWithoutCountryCode = substr($cleanNumber, 3); // Remove "241"

        if (empty($numberWithoutCountryCode)) {
            return null;
        }

        // Apply Gabon number transformation rules
        $transformedNumber = $this->applyGabonTransformation($numberWithoutCountryCode);

        if ($transformedNumber === null) {
            return null;
        }

        return '241' . $transformedNumber;
    }

    private function formatLocalGabonNumber(string $localNumber): ?string
    {
        // Remove leading zero if present
        if (str_starts_with($localNumber, '0')) {
            $localNumber = substr($localNumber, 1);
        }

        // Apply Gabon number transformation rules
        $transformedNumber = $this->applyGabonTransformation($localNumber);

        if ($transformedNumber === null) {
            return null;
        }

        return '241' . $transformedNumber;
    }

    private function applyGabonTransformation(string $number): ?string
    {
        // Remove any remaining leading zeros
        $number = ltrim($number, '0');

        if (empty($number)) {
            return null;
        }

        $length = strlen($number);

        // If already 8 digits, check if transformation is needed
        if ($length === 8) {
            return $this->transformGabonPrefix($number);
        }

        // If 9 digits, check the first two digits for transformation
        if ($length === 9) {
            $firstTwoDigits = substr($number, 0, 2);

            // Check if this matches the patterns that need transformation
            if (in_array($firstTwoDigits, ['02', '06', '05', '07', '04'])) {
                $remainingDigits = substr($number, 2);
                $transformedPrefix = $this->getTransformedPrefix($firstTwoDigits);
                return $transformedPrefix . $remainingDigits;
            }

            // If no transformation needed but has 9 digits, remove first digit if it's 0
            if (str_starts_with($number, '0')) {
                $number = substr($number, 1);
                if (strlen($number) === 8) {
                    return $this->transformGabonPrefix($number);
                }
            }
        }

        // If 7 digits, need to add appropriate prefix
        if ($length === 7) {
            $firstDigit = $number[0];

            switch ($firstDigit) {
                case '2':
                case '5':
                case '6':
                    return '6' . $number;
                case '4':
                case '7':
                    return '7' . $number;
                default:
                    return null;
            }
        }

        // Invalid length
        return null;
    }

    private function transformGabonPrefix(string $number): string
    {
        // Check if the number starts with patterns that need transformation
        $firstTwoDigits = substr($number, 0, 2);

        if (in_array($firstTwoDigits, ['02', '06', '05', '07', '04'])) {
            $remainingDigits = substr($number, 2);
            $transformedPrefix = $this->getTransformedPrefix($firstTwoDigits);
            return $transformedPrefix . $remainingDigits;
        }

        return $number;
    }

    private function getTransformedPrefix(string $prefix): string
    {
        $transformationMap = [
            '02' => '62',
            '06' => '66',
            '05' => '65',
            '07' => '77',
            '04' => '74'
        ];

        return $transformationMap[$prefix] ?? $prefix;
    }
    /**
     * Send template message and save to WhatsAppMessage table
     */
    public function sendTemplateMessage(Business $business, string $phoneNumberId, string $waId, $template, array $variables = [], ?int $campaignId = null, ?int $contactId = null): array
    {
        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            return [
                'success' => false,
                'error' => 'No active Facebook credentials found'
            ];
        }

        try {
            $to = $this->formatWaId($waId);
            if ($to === null) {
                Log::warning('Invalid WhatsApp number format', [
                    'original_number' => $waId,
                    'business_id' => $business->id,
                    'clean_number' => preg_replace('/[^0-9]/', '', $waId)
                ]);

                throw new \InvalidArgumentException(
                    "Invalid Gabon WhatsApp number format: '{$waId}'. " .
                        "Please use a valid Gabon mobile number in international format (+241...) or local format."
                );
            }

            $templateName = is_string($template) ? $template : $template->name;
            // Prepare template data
            $templateData = [
                'messaging_product' => 'whatsapp',
                "recipient_type" =>  "individual",
                'to' => $to, // No + sign - Facebook API doesn't accept it
                'type' => 'template',
                'template' => [
                    'name' => $templateName,
                    'language' => [
                        'code' => 'fr'
                    ]
                ]
            ];

            // Build components array
            $components = [];

            // ✅ Add header component if template has media in header
            if (is_object($template) && isset($template->header) && is_array($template->header)) {
                $headerFormat = $template->header['format'] ?? null;

                if (in_array($headerFormat, ['IMAGE', 'VIDEO', 'DOCUMENT'])) {
                    Log::info('Adding header component with media', [
                        'template_name' => $templateName,
                        'header_format' => $headerFormat,
                        'header_data' => $template->header
                    ]);

                    $headerParameter = [];
                    $mediaType = strtolower($headerFormat); // IMAGE -> image

                    // Get metadata
                    $metadata = is_array($template->metadata) ? $template->metadata : [];
                    $mediaId = $metadata['media_id'] ?? null;
                    $localMediaUrl = $metadata['local_media_url'] ?? null;

                    // Priority: media_id > local_media_url > header_handle (from example)
                    if (!empty($mediaId)) {
                        // Use uploaded media ID from metadata
                        $headerParameter[$mediaType] = [
                            'id' => $mediaId
                        ];
                        Log::info('Using media_id for header', ['media_id' => $mediaId]);
                    } elseif (!empty($localMediaUrl)) {
                        // Use local storage URL from metadata
                        $headerParameter[$mediaType] = [
                            'link' => $localMediaUrl
                        ];
                        Log::info('Using local_media_url for header', ['url' => $localMediaUrl]);
                    } elseif (!empty($template->header['example']['header_handle'][0] ?? null)) {
                        // Extract from header example (from Facebook sync)
                        $headerHandle = $template->header['example']['header_handle'][0];

                        // ⚠️ SKIP WhatsApp CDN URLs - they are temporary and protected!
                        $isWhatsAppCdn = str_contains($headerHandle, 'scontent.whatsapp.net') ||
                            str_contains($headerHandle, 'lookaside.fbsbx.com') ||
                            str_contains($headerHandle, 'mmg.whatsapp.net');

                        if ($isWhatsAppCdn) {
                            Log::warning('Skipping WhatsApp CDN URL (temporary and protected)', [
                                'template_name' => $templateName,
                                'url' => substr($headerHandle, 0, 100) . '...'
                            ]);
                            // Don't use this URL - it will cause 403 error
                        } else {
                            // Check if it's a media ID (numeric) or a valid public URL
                            if (is_numeric($headerHandle) || preg_match('/^\d+$/', $headerHandle)) {
                                $headerParameter[$mediaType] = [
                                    'id' => $headerHandle
                                ];
                                Log::info('Using header_handle as media_id', ['media_id' => $headerHandle]);
                            } else {
                                $headerParameter[$mediaType] = [
                                    'link' => $headerHandle
                                ];
                                Log::info('Using header_handle as link', ['url' => $headerHandle]);
                            }
                        }
                    }

                    if (empty($headerParameter)) {
                        Log::warning('Template has media header but no valid media source provided', [
                            'template_name' => $templateName,
                            'header_format' => $headerFormat,
                            'has_media_id' => !empty($mediaId),
                            'has_local_media_url' => !empty($localMediaUrl),
                            'has_header_handle' => !empty($template->header['example']['header_handle'][0] ?? null),
                            'metadata' => $metadata
                        ]);
                    }

                    if (!empty($headerParameter)) {
                        $components[] = [
                            'type' => 'header',
                            'parameters' => [
                                array_merge(['type' => $mediaType], $headerParameter)
                            ]
                        ];
                    }
                }
            }

            // Add body parameters if variables provided
            if (!empty($variables)) {
                $components[] = [
                    'type' => 'body',
                    'parameters' => array_map(function ($var) {
                        return ['type' => 'text', 'text' => (string)$var];
                    }, $variables)
                ];

                // Authentication templates require special button component for copy code button
                if ($templateName === 'business_authentication') {
                    $otpCode = !empty($variables) ? reset($variables) : null;

                    Log::info('🔐 Adding authentication template button component', [
                        'otp_code' => $otpCode,
                        'template' => $templateName
                    ]);

                    $components[] = [
                        'type' => 'button',
                        'sub_type' => 'url',
                        'index' => 0, // Integer, not string - this was the bug!
                        'parameters' => [
                            [
                                'type' => 'text',
                                'text' => (string)$otpCode // Ensure string type
                            ]
                        ]
                    ];
                }
            }

            // ✅ Check if template has CATALOG button
            if (is_object($template) && isset($template->buttons) && is_array($template->buttons)) {
                foreach ($template->buttons as $index => $button) {
                    if (isset($button['type']) && strtoupper($button['type']) === 'CATALOG') {
                        Log::info('🛒 Adding catalog button component', [
                            'template_name' => $templateName,
                            'button_index' => $index
                        ]);

                        // Build catalog button component
                        $catalogComponent = [
                            'type' => 'button',
                            'sub_type' => 'CATALOG',
                            'index' => $index,
                            'parameters' => [
                                [
                                    'type' => 'action',
                                    'action' => []
                                ]
                            ]
                        ];

                        // Add thumbnail_product_retailer_id if provided in variables
                        if (isset($variables['thumbnail_product_retailer_id'])) {
                            $catalogComponent['parameters'][0]['action']['thumbnail_product_retailer_id'] = $variables['thumbnail_product_retailer_id'];
                            Log::info('📸 Using specific product thumbnail', [
                                'retailer_id' => $variables['thumbnail_product_retailer_id']
                            ]);
                        }

                        $components[] = $catalogComponent;
                        break; // Only one catalog button allowed
                    }
                }
            }

            // ✅ Add components to template object (CRITICAL FIX from StackOverflow)
            if (!empty($components)) {
                $templateData['template']['components'] = $components;
            }

            // Log the complete template data for debugging
            Log::info('📤 Sending template message', [
                'template_name' => $templateName,
                'to' => $to,
                'phone_number_id' => $phoneNumberId,
                'has_components' => !empty($components),
                'components_count' => count($components),
                'full_payload' => $templateData // Log entire payload for debugging
            ]);

            // Send message via Facebook API
            $response = Http::withToken($credentials->access_token)
                ->post("https://graph.facebook.com/v24.0/{$phoneNumberId}/messages", $templateData);

            if ($response->failed()) {
                $error = 'Failed to send template message: ' . $response->body();
                Log::error($error, [
                    'business_id' => $business->id,
                    'phone_number_id' => $phoneNumberId,
                    'wa_id' => $waId,
                    'template' => is_string($template) ? $template : $template->name
                ]);

                return [
                    'success' => false,
                    'error' => $error,
                    'response' => $response->json()
                ];
            }

            $responseData = $response->json();
            $messageId = $responseData['messages'][0]['id'] ?? null;

            // ✅ Capturer les headers de rate limit API
            try {
                \App\Models\FacebookApiUsage::parseAndStore(
                    $business->id,
                    $response->headers(),
                    $phoneNumberId
                );
            } catch (\Exception $e) {
                Log::warning('Failed to capture Facebook API rate limit headers', [
                    'error' => $e->getMessage(),
                    'business_id' => $business->id
                ]);
            }

            Log::info('Template message sent successfully', [
                'business_id' => $business->id,
                'phone_number_id' => $phoneNumberId,
                'wa_id' => $to,
                'message_id' => $messageId,
                'template' => is_string($template) ? $template : $template->name
            ]);

            // Save message to WhatsAppMessage table
            $this->saveOutgoingTemplateMessage($business, $phoneNumberId, $to, $template, $variables, $responseData, $campaignId, $contactId);

            return [
                'success' => true,
                'message_id' => $messageId,
                'response' => $responseData
            ];
        } catch (\Exception $e) {
            Log::error('Template message sending failed', [
                'business_id' => $business->id,
                'phone_number_id' => $phoneNumberId,
                'wa_id' => $to,
                'template' => is_string($template) ? $template : $template->name,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Send text message and save to WhatsAppMessage table
     */
    public function sendTextMessage(Business $business, string $phoneNumberId, string $waId, string $text, ?string $conversationUuid = null): array
    {
        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            return [
                'success' => false,
                'error' => 'No active Facebook credentials found'
            ];
        }

        try {
            // Prepare message data
            $messageData = [
                'messaging_product' => 'whatsapp',
                'to' => $waId,
                'type' => 'text',
                'text' => [
                    'body' => $text
                ]
            ];

            // Send message via Facebook API
            $response = Http::withToken($credentials->access_token)
                ->post("https://graph.facebook.com/v24.0/{$phoneNumberId}/messages", $messageData);

            if ($response->failed()) {
                $error = 'Failed to send text message: ' . $response->body();
                Log::error($error, [
                    'business_id' => $business->id,
                    'phone_number_id' => $phoneNumberId,
                    'wa_id' => $waId
                ]);

                return [
                    'success' => false,
                    'error' => $error,
                    'response' => $response->json()
                ];
            }

            $responseData = $response->json();
            $messageId = $responseData['messages'][0]['id'] ?? null;

            // ✅ Capturer les headers de rate limit API
            try {
                \App\Models\FacebookApiUsage::parseAndStore(
                    $business->id,
                    $response->headers(),
                    $phoneNumberId
                );
            } catch (\Exception $e) {
                Log::warning('Failed to capture Facebook API rate limit headers', [
                    'error' => $e->getMessage(),
                    'business_id' => $business->id
                ]);
            }

            Log::info('Text message sent successfully', [
                'business_id' => $business->id,
                'phone_number_id' => $phoneNumberId,
                'wa_id' => $waId,
                'message_id' => $messageId
            ]);

            // Save message to WhatsAppMessage table
            $this->saveOutgoingMessage($business, $phoneNumberId, $waId, 'text', ['text' => $text], $responseData, $conversationUuid);

            return [
                'success' => true,
                'message_id' => $messageId,
                'response' => $responseData
            ];
        } catch (\Exception $e) {
            Log::error('Text message sending failed', [
                'business_id' => $business->id,
                'phone_number_id' => $phoneNumberId,
                'wa_id' => $waId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Save outgoing template message to WhatsAppMessage table
     */
    protected function saveOutgoingTemplateMessage(Business $business, string $phoneNumberId, string $waId, $template, array $variables, array $response, ?int $campaignId = null, ?int $contactId = null): void
    {
        try {
            $waId = $this->formatWaId($waId);
            // Get the WhatsApp phone number record to fetch display_phone_number
            $whatsappPhoneNumber = \App\Models\WhatsAppPhoneNumber::where('business_id', $business->id)
                ->where('phone_number_id', $phoneNumberId)
                ->first();

            $templateName = is_string($template) ? $template : $template->name;
            $templateBody = is_object($template) ? $template->body : '';

            // Replace variables in template body if provided
            if (!empty($variables) && !empty($templateBody)) {
                foreach ($variables as $index => $value) {
                    $templateBody = str_replace('{{' . ($index + 1) . '}}', $value, $templateBody);
                }
            }

            $messageData = [
                'business_id' => $business->id,
                'message_id' => $response['messages'][0]['id'] ?? \Illuminate\Support\Str::uuid(),
                'wa_id' => $waId,
                'contact_id' => $contactId, // ✅ NOW INCLUDED (optional)
                'phone_number_id' => $phoneNumberId,
                'display_phone_number' => $whatsappPhoneNumber?->display_phone_number,
                'type' => 'template',
                'content' => $templateBody,
                'conversation_uuid' => $this->formatWaId($waId) . '_' . $phoneNumberId,
                'template_name' => $templateName,
                'template_language' => 'fr',
                'template_components' => is_object($template) ? $template->components : null,
                'direction' => 'outbound',
                'message_source' => 'campaign',
                'campaign_id' => $campaignId,
                'timestamp' => now(),
                'status' => 'sent',
                'raw_data' => $response,
                'analytics_data' => [
                    'is_campaign_message' => true,
                    'template_used' => $templateName,
                    'variables_count' => count($variables)
                ]
            ];

            \App\Models\WhatsAppMessage::create($messageData);

            Log::info('Template message saved to database', [
                'business_id' => $business->id,
                'message_id' => $messageData['message_id'],
                'template' => $templateName
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to save outgoing template message', [
                'error' => $e->getMessage(),
                'business_id' => $business->id,
                'wa_id' => $waId,
                'template' => is_string($template) ? $template : $template->name
            ]);
        }
    }

    /**
     * Unified method to save outgoing messages of any type
     */
    public function saveOutgoingMessage(Business $business, string $phoneNumberId, string $waId, string $type, array $messageData, array $response, ?string $conversationUuid = null, array $additionalData = []): void
    {
        try {
            // Get the WhatsApp phone number record to fetch display_phone_number
            $whatsappPhoneNumber = \App\Models\WhatsAppPhoneNumber::where('business_id', $business->id)
                ->where('phone_number_id', $phoneNumberId)
                ->first();

            $baseMessageData = [
                'business_id' => $business->id,
                'message_id' => $response['messages'][0]['id'] ?? \Illuminate\Support\Str::uuid(),
                'wa_id' => $waId,
                'phone_number_id' => $phoneNumberId,
                'display_phone_number' => $whatsappPhoneNumber?->display_phone_number,
                'type' => $type,
                'direction' => 'outbound',
                'conversation_uuid' => $conversationUuid ?? ($this->formatWaId($waId) . '_' . $phoneNumberId),
                'timestamp' => now(),
                'status' => 'sent',
                'raw_data' => $response,
            ];

            // Type-specific data handling
            switch ($type) {
                case 'text':
                    $baseMessageData['content'] = $messageData['text'] ?? $messageData['content'] ?? '';
                    $baseMessageData['message_source'] = $additionalData['message_source'] ?? 'manual';
                    $baseMessageData['analytics_data'] = [
                        'is_campaign_message' => $additionalData['is_campaign_message'] ?? false,
                        'message_length' => strlen($baseMessageData['content']),
                        'ai_generated' => $additionalData['ai_generated'] ?? false,
                        'session_uuid' => $additionalData['session_uuid'] ?? null,
                        'ai_agent_id' => $additionalData['ai_agent_id'] ?? null,
                        'ai_agent_name' => $additionalData['ai_agent_name'] ?? null,
                        'ai_agent_type' => $additionalData['ai_agent_type'] ?? null,
                    ];
                    break;

                case 'location':
                    $baseMessageData['content'] = $messageData['location_name'] ?? 'Location partagée';
                    $baseMessageData['message_source'] = $additionalData['message_source'] ?? 'ai_agent';
                    $baseMessageData['latitude'] = $messageData['latitude'] ?? null;
                    $baseMessageData['longitude'] = $messageData['longitude'] ?? null;
                    $baseMessageData['location_name'] = $messageData['location_name'] ?? '';
                    $baseMessageData['location_address'] = $messageData['location_address'] ?? $messageData['text'] ?? '';
                    $baseMessageData['analytics_data'] = [
                        'is_campaign_message' => false,
                        'is_location_message' => true,
                        'ai_generated' => $additionalData['ai_generated'] ?? false,
                        'session_uuid' => $additionalData['session_uuid'] ?? null,
                        'ai_agent_id' => $additionalData['ai_agent_id'] ?? null,
                        'ai_agent_name' => $additionalData['ai_agent_name'] ?? null,
                        'ai_agent_type' => $additionalData['ai_agent_type'] ?? null,
                    ];
                    break;

                case 'image':
                case 'video':
                case 'audio':
                case 'document':
                case 'sticker':
                    $baseMessageData['content'] = $messageData['caption'] ?? "[{$type} message]";
                    $baseMessageData['message_source'] = $additionalData['message_source'] ?? 'manual';
                    $baseMessageData['media_id'] = $additionalData['media_id'] ?? null;
                    $baseMessageData['media_mime_type'] = $additionalData['media_mime_type'] ?? null;
                    $baseMessageData['media_filename'] = $additionalData['media_filename'] ?? null;
                    $baseMessageData['media_file_size'] = $additionalData['media_file_size'] ?? null;
                    $baseMessageData['media_url'] = $additionalData['media_url'] ?? null;
                    $baseMessageData['local_media_path'] = $additionalData['local_media_path'] ?? null;
                    $baseMessageData['analytics_data'] = [
                        'is_campaign_message' => $additionalData['is_campaign_message'] ?? false,
                        'media_type' => $type,
                        'ai_generated' => $additionalData['ai_generated'] ?? false,
                        'session_uuid' => $additionalData['session_uuid'] ?? null,
                        'ai_agent_id' => $additionalData['ai_agent_id'] ?? null,
                        'ai_agent_name' => $additionalData['ai_agent_name'] ?? null,
                        'ai_agent_type' => $additionalData['ai_agent_type'] ?? null,
                    ];
                    break;

                case 'template':
                    $baseMessageData['content'] = $messageData['content'] ?? 'Template message';
                    $baseMessageData['message_source'] = $additionalData['message_source'] ?? 'ai_agent';
                    $baseMessageData['template_name'] = $messageData['template_name'] ?? null;
                    $baseMessageData['analytics_data'] = [
                        'is_campaign_message' => false,
                        'is_template_message' => true,
                        'ai_generated' => $additionalData['ai_generated'] ?? false,
                        'session_uuid' => $additionalData['session_uuid'] ?? null,
                        'ai_agent_id' => $additionalData['ai_agent_id'] ?? null,
                        'ai_agent_name' => $additionalData['ai_agent_name'] ?? null,
                        'ai_agent_type' => $additionalData['ai_agent_type'] ?? null,
                    ];
                    break;

                default:
                    $baseMessageData['content'] = $messageData['content'] ?? $messageData['text'] ?? '';
                    $baseMessageData['message_source'] = $additionalData['message_source'] ?? 'manual';
                    $baseMessageData['analytics_data'] = [
                        'is_campaign_message' => $additionalData['is_campaign_message'] ?? false,
                        'ai_generated' => $additionalData['ai_generated'] ?? false,
                        'session_uuid' => $additionalData['session_uuid'] ?? null,
                        'ai_agent_id' => $additionalData['ai_agent_id'] ?? null,
                        'ai_agent_name' => $additionalData['ai_agent_name'] ?? null,
                        'ai_agent_type' => $additionalData['ai_agent_type'] ?? null,
                    ];
                    break;
            }

            // Add AI-specific data if provided
            if (isset($additionalData['whapush_ai_id'])) {
                $baseMessageData['whapush_ai_id'] = $additionalData['whapush_ai_id'];
            }

            \App\Models\WhatsAppMessage::create($baseMessageData);

            Log::info('Outgoing message saved to database', [
                'business_id' => $business->id,
                'message_id' => $baseMessageData['message_id'],
                'type' => $type,
                'wa_id' => $waId
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to save outgoing message', [
                'error' => $e->getMessage(),
                'business_id' => $business->id,
                'wa_id' => $waId,
                'type' => $type
            ]);
        }
    }

    /**
     * Save incoming WhatsApp message to database with subscription limits
     */
    public function saveIncomingMessage(array $message, Business $business, string $phoneNumberId, bool $hasValidSubscription = true, array $contacts = []): ?\App\Models\WhatsAppMessage
    {
        try {
            // Find or create contact
            $waId = $message['from'] ?? null;
            $messageData = $message;
            $profileName = null;

            // Extract profile name from the contacts array passed from webhook
            // Priority: 1) Passed contacts array, 2) Message's contacts array
            $contactsToSearch = !empty($contacts) ? $contacts : ($messageData['contacts'] ?? []);

            if (!empty($contactsToSearch)) {
                foreach ($contactsToSearch as $contact) {
                    if (($contact['wa_id'] ?? null) === $waId) {
                        $profileName = $contact['profile']['name'] ?? null;
                        Log::info('Profile name extracted from contacts', [
                            'wa_id' => $waId,
                            'profile_name' => $profileName
                        ]);
                        break;
                    }
                }
            }

            // Format the wa_id before using it
            $to = $this->formatWaId($waId);
            $waId = $to;
            if ($to === null) {
                Log::warning('Invalid WhatsApp number format', [
                    'original_number' => $waId,
                    'business_id' => $business->id,
                    'clean_number' => preg_replace('/[^0-9]/', '', $waId)
                ]);

                throw new \InvalidArgumentException(
                    "Invalid Gabon WhatsApp number format: '{$waId}'. " .
                        "Please use a valid Gabon mobile number in international format (+241...) or local format."
                );
            }
            $waId = $to;
            if (!$waId) {
                Log::warning('Message missing sender wa_id', ['message' => $message]);
                return null;
            }

            $contact = \App\Models\WhatsAppContact::updateOrCreate(
                ['wa_id' => $waId, 'business_id' => $business->id],
                ['phone_number' => $waId, 'name' => $profileName ?? $waId,]
            );

            // Find the WhatsApp phone number record
            $whatsappPhoneNumber = \App\Models\WhatsAppPhoneNumber::where('business_id', $business->id)
                ->where('phone_number_id', $phoneNumberId)
                ->first();

            // Generate conversation UUID using the new format: wa_id_phone_number_id
            $conversationUuid = $this->formatWaId($waId) . '_' . $phoneNumberId;

            // Extract message data
            $messageData = [
                'business_id' => $business->id,
                'message_id' => $message['id'],
                'wa_id' => $waId,
                'phone_number_id' => $phoneNumberId,
                'display_phone_number' => $whatsappPhoneNumber?->display_phone_number,
                'type' => $message['type'] ?? 'unknown',
                'timestamp' => isset($message['timestamp']) ?
                    \Carbon\Carbon::createFromTimestamp($message['timestamp']) : now(),
                'direction' => 'inbound',
                'message_source' => 'webhook',
                'webhook_event_type' => 'messages',
                'conversation_uuid' => $conversationUuid,
                'raw_data' => $message,
            ];

            // Log if phone number not found in our system
            if (!$whatsappPhoneNumber) {
                Log::warning('WhatsApp phone number not found in system', [
                    'phone_number_id' => $phoneNumberId,
                    'business_id' => $business->id
                ]);
            }

            // Handle different message types
            $this->extractMessageTypeData($message, $messageData, $business, $hasValidSubscription);

            // Handle context (replies/reactions)
            if (isset($message['context'])) {
                $this->extractContextData($message['context'], $messageData, $business);
            }

            // Check if message already exists (idempotency)
            $existingMessage = \App\Models\WhatsAppMessage::where('message_id', $message['id'])
                ->where('business_id', $business->id)
                ->first();

            if ($existingMessage) {
                Log::info('Message already exists, skipping duplicate', [
                    'message_id' => $message['id'],
                    'existing_id' => $existingMessage->id,
                    'business_id' => $business->id
                ]);
                return $existingMessage;
            }

            // Create WhatsApp message
            $whatsappMessage = \App\Models\WhatsAppMessage::create($messageData);

            Log::info('Incoming message saved successfully', [
                'message_id' => $message['id'],
                'type' => $messageData['type'],
                'wa_id' => $waId,
                'business_id' => $business->id
            ]);

            return $whatsappMessage;
        } catch (\Exception $e) {
            Log::error('Failed to save incoming message', [
                'error' => $e->getMessage(),
                'message' => $message,
                'business_id' => $business->id
            ]);
            return null;
        }
    }

    /**
     * Extract message type specific data for incoming messages
     */
    protected function extractMessageTypeData(array $message, array &$messageData, Business $business, bool $hasValidSubscription = true): void
    {
        $type = $message['type'] ?? 'unknown';

        switch ($type) {
            case 'text':
                $messageData['content'] = $message['text']['body'] ?? '';
                break;

            case 'image':
            case 'video':
            case 'audio':
            case 'document':
            case 'sticker':
                if (isset($message[$type])) {
                    $media = $message[$type];
                    $messageData['media_id'] = $media['id'] ?? null;
                    $messageData['media_mime_type'] = $media['mime_type'] ?? null;
                    $messageData['media_sha256'] = $media['sha256'] ?? null;
                    $messageData['media_filename'] = $media['filename'] ?? null;
                    $messageData['content'] = $media['caption'] ?? '';

                    // Download and store media file
                    if (!empty($messageData['media_id'])) {
                        $this->downloadAndStoreMedia($messageData, $type, $business);
                    }

                    if ($type === 'sticker') {
                        $messageData['sticker_data'] = $media;
                        $messageData['media_is_animated'] = $media['animated'] ?? false;
                    }

                    if ($type === 'audio') {
                        $messageData['audio_duration'] = $media['duration'] ?? null;
                        $messageData['audio_is_voice_note'] = $media['voice'] ?? false;
                    }

                    if ($type === 'video') {
                        $messageData['video_duration'] = $media['duration'] ?? null;
                        $messageData['video_caption'] = $media['caption'] ?? null;
                    }

                    if ($type === 'document') {
                        $messageData['document_caption'] = $media['caption'] ?? null;
                        $messageData['document_size'] = $media['filesize'] ?? null;
                    }
                }
                break;

            case 'location':
                $location = $message['location'] ?? [];
                $messageData['content'] = 'Location shared';
                $messageData['latitude'] = $location['latitude'] ?? null;
                $messageData['longitude'] = $location['longitude'] ?? null;
                $messageData['location_name'] = $location['name'] ?? null;
                $messageData['location_address'] = $location['address'] ?? null;
                break;

            case 'contacts':
                $messageData['content'] = 'Contact(s) shared';
                $messageData['shared_contacts'] = $message['contacts'] ?? [];
                break;

            case 'interactive':
                $interactive = $message['interactive'] ?? [];
                $messageData['interactive_type'] = $interactive['type'] ?? null;
                $messageData['interactive_data'] = $interactive;

                if (isset($interactive['button_reply'])) {
                    $messageData['content'] = $interactive['button_reply']['title'] ?? '';
                    $messageData['button_payload'] = $interactive['button_reply']['id'] ?? null;
                } elseif (isset($interactive['list_reply'])) {
                    $messageData['content'] = $interactive['list_reply']['title'] ?? '';
                    $messageData['list_id'] = $interactive['list_reply']['id'] ?? null;
                }
                break;

            case 'button':
                $messageData['content'] = $message['button']['text'] ?? '';
                $messageData['button_payload'] = $message['button']['payload'] ?? null;
                break;

            case 'reaction':
                // Handle message reactions (emoji reactions to previous messages)
                $reaction = $message['reaction'] ?? [];
                $emoji = $reaction['emoji'] ?? '👍';
                $messageData['content'] = "Réaction: {$emoji}";
                $messageData['reaction_emoji'] = $emoji;
                $messageData['reaction_message_id'] = $reaction['message_id'] ?? null;
                
                // Log reaction details
                Log::info('Message reaction received', [
                    'emoji' => $emoji,
                    'reacted_to_message_id' => $reaction['message_id'] ?? null,
                    'business_id' => $business->id
                ]);
                break;

            case 'system':
                // Handle system messages (e.g., contact changed number, etc.)
                $messageData['content'] = 'Message système';
                $messageData['system_type'] = $message['system']['type'] ?? null;
                $messageData['system_body'] = $message['system']['body'] ?? null;
                break;

            default:
                $messageData['content'] = $message['text']['body'] ?? 'Unknown message type';
                break;
        }
    }

    /**
     * Extract context data for replies and reactions
     */
    protected function extractContextData(array $context, array &$messageData, Business $business): void
    {
        $messageData['context_message_id'] = $context['id'] ?? null;
        $messageData['context_from'] = $context['from'] ?? null;

        if (isset($context['referred_product'])) {
            $messageData['referred_product'] = $context['referred_product'];
        }
    }

    /**
     * Save AI-generated outgoing message to database (unified method)
     */
    public function saveAIOutgoingMessage(array $response, Business $business, string $phoneNumberId, string $waId, array $messageData, string $sessionUuid, $AYOSPUSHAI): void
    {
        $additionalData = [
            'message_source' => 'ai_agent',
            'ai_generated' => true,
            'session_uuid' => $sessionUuid,
            'ai_agent_id' => $AYOSPUSHAI->id,
            'ai_agent_name' => $AYOSPUSHAI->name,
            'ai_agent_type' => $AYOSPUSHAI->agent_type,
            'whapush_ai_id' => $AYOSPUSHAI->id,
        ];

        // Add template-specific data if applicable
        if (($messageData['type'] ?? '') === 'template') {
            $messageData['template_name'] = $messageData['template_name'] ?? null;
        }

        $this->saveOutgoingMessage(
            $business,
            $phoneNumberId,
            $waId,
            $messageData['type'] ?? 'text',
            $messageData,
            $response,
            $sessionUuid,
            $additionalData
        );
    }

    /**
     * Download and store media file according to Facebook Graph API documentation
     * Based on: https://developers.facebook.com/docs/whatsapp/cloud-api/reference/media#download-media
     */
    protected function downloadAndStoreMedia(array &$messageData, string $mediaType, Business $business = null): void
    {
        try {
            $mediaId = $messageData['media_id'];
            if (!$mediaId) {
                Log::warning('No media ID provided for download', [
                    'media_type' => $mediaType,
                    'message_data' => $messageData
                ]);
                return;
            }

            Log::info('Starting media download process', [
                'media_id' => $mediaId,
                'media_type' => $mediaType,
                'mime_type' => $messageData['media_mime_type'] ?? 'unknown'
            ]);

            // Step 1: Get business credentials for API access
            $credentials = $this->getCredentials($business);

            if (!$credentials) {
                Log::error('No active Facebook credentials found for media download', [
                    'business_id' => $business?->id,
                    'media_id' => $mediaId
                ]);
                $messageData['media_downloaded'] = false;
                $messageData['media_download_error'] = 'No active Facebook credentials found';
                return;
            }

            // Step 2: Get media URL from Facebook API using media ID
            // GET /{media-id}/ with access token
            $mediaService = app(\App\Http\Services\WhatsAppMediaService::class);
            $mediaService->setCredentials($credentials->access_token);

            try {
                $mediaUrl = $mediaService->getMediaUrl($mediaId);

                if (!$mediaUrl) {
                    Log::error('Failed to get media URL from Facebook', [
                        'media_id' => $mediaId,
                        'media_type' => $mediaType
                    ]);
                    return;
                }

                Log::info('Media URL retrieved successfully', [
                    'media_id' => $mediaId,
                    'media_url' => $mediaUrl
                ]);

                // Step 3: Download media from Facebook servers and store locally
                // The media URL is only valid for 5 minutes according to Facebook docs
                $localPath = $mediaService->downloadMedia($mediaUrl, $mediaId, $mediaType);

                if ($localPath) {
                    // Step 4: Update message data with local storage information
                    $messageData['media_url'] = $mediaService->getPublicUrl($localPath);
                    $messageData['local_media_path'] = $localPath; // Use existing column name
                    $messageData['media_downloaded'] = true;
                    $messageData['media_download_timestamp'] = now();

                    // Get file size from local storage
                    $fileSize = $mediaService->getMediaSize($localPath);
                    if ($fileSize > 0) {
                        $messageData['media_file_size'] = $fileSize;
                    }

                    Log::info('Media downloaded and stored successfully', [
                        'media_id' => $mediaId,
                        'media_type' => $mediaType,
                        'local_path' => $localPath,
                        'public_url' => $messageData['media_url'],
                        'file_size' => $fileSize
                    ]);
                } else {
                    Log::error('Failed to download media from Facebook', [
                        'media_id' => $mediaId,
                        'media_url' => $mediaUrl,
                        'media_type' => $mediaType
                    ]);

                    // Mark as download failed but still save the media ID for potential retry
                    $messageData['media_downloaded'] = false;
                    $messageData['media_download_error'] = 'Failed to download from Facebook servers';
                }
            } catch (\Exception $e) {
                Log::error('Media download process failed', [
                    'media_id' => $mediaId,
                    'media_type' => $mediaType,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);

                // Mark as download failed but still save the media ID for potential retry
                $messageData['media_downloaded'] = false;
                $messageData['media_download_error'] = $e->getMessage();
            }
        } catch (\Exception $e) {
            Log::error('Critical error in media download process', [
                'media_id' => $messageData['media_id'] ?? 'unknown',
                'media_type' => $mediaType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Ensure we don't break message saving even if media download fails
            $messageData['media_downloaded'] = false;
            $messageData['media_download_error'] = 'Critical error: ' . $e->getMessage();
        }
    }

    /**
     * Sync templates from Facebook WABA account
     */
    public function syncTemplates(Business $business): array
    {
        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            return [
                'success' => false,
                'error' => 'No active Facebook credentials found'
            ];
        }

        try {
            $syncedCount = 0;
            $errors = [];

            // Get all WABA accounts for this business
            $wabaAccounts = $business->wabaAccounts;

            foreach ($wabaAccounts as $wabaAccount) {
                Log::info('Syncing templates for WABA account', [
                    'business_id' => $business->id,
                    'waba_id' => $wabaAccount->waba_id
                ]);

                // Get templates from Facebook
                $response = Http::withToken($credentials->access_token)
                    ->get("https://graph.facebook.com/v24.0/{$wabaAccount->waba_id}/message_templates");

                if ($response->failed()) {
                    $error = 'Failed to fetch templates for WABA ' . $wabaAccount->waba_id . ': ' . $response->body();
                    Log::error($error);
                    $errors[] = $error;
                    continue;
                }

                $templateData = $response->json();
                $templates = $templateData['data'] ?? [];

                foreach ($templates as $template) {
                    $syncedCount += $this->syncSingleTemplate($business, $wabaAccount, $template);
                }
                // Create utility templates if they don't exist
                $this->createUtilityTemplatesIfNeeded($wabaAccount);
            }

            return [
                'success' => true,
                'synced_count' => $syncedCount,
                'errors' => $errors
            ];
        } catch (\Exception $e) {
            Log::error('Template sync failed', [
                'business_id' => $business->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Sync a single template from Facebook data
     */
    protected function syncSingleTemplate(Business $business, $wabaAccount, array $templateData): int
    {
        try {
            // ✅ FIX: Check if template exists for THIS SPECIFIC WABA (including soft-deleted)
            // Templates are unique per (business_id, waba_account_id, name, language)
            $existingTemplate = \App\Models\WhatsAppTemplate::withTrashed()
                ->where('business_id', $business->id)
                ->where('waba_account_id', $wabaAccount->id) // ✅ Added: Filter by WABA
                ->where('name', $templateData['name'])
                ->where('language', $templateData['language'])
                ->first();

            $templateFields = [
                'business_id' => $business->id,
                'waba_account_id' => $wabaAccount->id,
                'name' => $templateData['name'],
                'display_name' => $templateData['name'],
                'language' => $templateData['language'],
                'category' => strtoupper($templateData['category'] ?? 'UTILITY'),
                'status' => strtolower($templateData['status'] ?? 'pending'),
                'facebook_template_id' => $templateData['id'],
                'facebook_status' => $templateData['status'] ?? 'PENDING',
                'components' => $templateData['components'] ?? [],
                'last_used_at' => null,
                'is_active' => ($templateData['status'] ?? '') === 'APPROVED',
            ];

            // Extract body text from components
            $bodyComponent = collect($templateData['components'] ?? [])->firstWhere('type', 'BODY');
            if ($bodyComponent) {
                $templateFields['body'] = $bodyComponent['text'] ?? '';
            }

            // Extract header from components with proper format
            $headerComponent = collect($templateData['components'] ?? [])->firstWhere('type', 'HEADER');
            if ($headerComponent) {
                // Store header with format information (IMAGE, VIDEO, DOCUMENT, TEXT)
                $templateFields['header'] = [
                    'format' => $headerComponent['format'] ?? null,
                    'text' => $headerComponent['text'] ?? null,
                    'example' => $headerComponent['example'] ?? null,
                ];

                Log::info('Template header extracted during sync', [
                    'template_name' => $templateData['name'],
                    'header_format' => $headerComponent['format'] ?? 'UNKNOWN',
                    'has_example' => isset($headerComponent['example'])
                ]);

                // 🆕 Download and store media locally for IMAGE, VIDEO, DOCUMENT headers
                $headerFormat = $headerComponent['format'] ?? null;
                $mediaFormats = ['IMAGE', 'VIDEO', 'DOCUMENT'];

                if (in_array($headerFormat, $mediaFormats)) {
                    $headerHandle = $headerComponent['example']['header_handle'][0] ?? null;

                    if ($headerHandle && filter_var($headerHandle, FILTER_VALIDATE_URL)) {
                        Log::info('🔄 Downloading template media from Facebook CDN', [
                            'template_name' => $templateData['name'],
                            'format' => $headerFormat,
                            'cdn_url' => substr($headerHandle, 0, 100) . '...'
                        ]);

                        try {
                            // Download media from WhatsApp CDN
                            $mediaContent = @file_get_contents($headerHandle);

                            if ($mediaContent === false) {
                                Log::warning('⚠️ Failed to download media from WhatsApp CDN', [
                                    'template_name' => $templateData['name'],
                                    'url' => substr($headerHandle, 0, 100) . '...'
                                ]);
                            } else {
                                // Determine file extension from format
                                $extension = match ($headerFormat) {
                                    'IMAGE' => 'png',
                                    'VIDEO' => 'mp4',
                                    'DOCUMENT' => 'pdf',
                                    default => 'bin'
                                };

                                // Create storage directory for business (same as template creation)
                                $storagePath = "template-media/business-{$business->id}";
                                $filename = uniqid(strtolower($headerFormat) . '_') . '_' . now()->format('YmdHis') . '.' . $extension;
                                $fullPath = storage_path('app/public/' . $storagePath . '/' . $filename);

                                // Ensure directory exists
                                $directory = dirname($fullPath);
                                if (!file_exists($directory)) {
                                    mkdir($directory, 0755, true);
                                }

                                // Save file
                                file_put_contents($fullPath, $mediaContent);

                                // Generate public URL (same as template creation)
                                $localMediaUrl = route('media.serve', [
                                    'business' => $business->id,
                                    'filename' => $filename
                                ]);

                                // Store in metadata
                                $templateFields['metadata'] = [
                                    'local_media_url' => $localMediaUrl,
                                    'original_cdn_url' => $headerHandle,
                                    'synced_at' => now()->toDateTimeString()
                                ];

                                Log::info('✅ Media downloaded and stored locally', [
                                    'template_name' => $templateData['name'],
                                    'filename' => $filename,
                                    'local_url' => $localMediaUrl
                                ]);
                            }
                        } catch (\Exception $e) {
                            Log::error('❌ Failed to download/store template media during sync', [
                                'template_name' => $templateData['name'],
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString()
                            ]);
                        }
                    }
                }
            }

            // Extract footer from components
            $footerComponent = collect($templateData['components'] ?? [])->firstWhere('type', 'FOOTER');
            if ($footerComponent) {
                $templateFields['footer'] = [
                    'text' => $footerComponent['text'] ?? null
                ];
            }

            // Extract buttons from components
            $buttonComponents = collect($templateData['components'] ?? [])->where('type', 'BUTTONS')->first();
            if ($buttonComponents) {
                $templateFields['buttons'] = $buttonComponents['buttons'] ?? [];
            }

            if ($existingTemplate) {
                // ✅ If template was soft-deleted, restore it first
                if ($existingTemplate->trashed()) {
                    $existingTemplate->restore();
                    Log::info('Restored soft-deleted template during sync', [
                        'template_id' => $existingTemplate->id,
                        'business_id' => $business->id,
                        'waba_account_id' => $wabaAccount->id,
                        'name' => $templateData['name']
                    ]);
                }

                $existingTemplate->update($templateFields);
                Log::info('Template updated from Facebook sync', [
                    'template_id' => $existingTemplate->id,
                    'business_id' => $business->id,
                    'waba_account_id' => $wabaAccount->id,
                    'waba_id' => $wabaAccount->waba_id,
                    'name' => $templateData['name'],
                    'language' => $templateData['language'],
                    'status' => $templateFields['status'],
                    'was_trashed' => $existingTemplate->wasRecentlyRestored ?? false
                ]);
            } else {
                $newTemplate = \App\Models\WhatsAppTemplate::create($templateFields);
                Log::info('New template created from Facebook sync', [
                    'template_id' => $newTemplate->id,
                    'business_id' => $business->id,
                    'waba_account_id' => $wabaAccount->id,
                    'waba_id' => $wabaAccount->waba_id,
                    'name' => $templateData['name'],
                    'language' => $templateData['language'],
                    'category' => $templateFields['category'],
                    'status' => $templateFields['status']
                ]);
            }

            return 1;
        } catch (\Exception $e) {
            Log::error('Failed to sync single template', [
                'business_id' => $business->id,
                'waba_account_id' => $wabaAccount->id ?? null,
                'waba_id' => $wabaAccount->waba_id ?? null,
                'template_name' => $templateData['name'] ?? 'unknown',
                'template_language' => $templateData['language'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 0;
        }
    }

    /**
     * Create utility templates if they don't exist
     */
    protected function createUtilityTemplatesIfNeeded(WabaAccount $wabaAccount): void
    {
        $credentials = $this->getCredentials($wabaAccount->business);
        if (!$credentials) {
            Log::error('No active Facebook credentials found for business', ['business_id' => $wabaAccount->business_id]);
            return;
        }

        // ✅ Check for welcome template for THIS specific WABA (including soft-deleted)
        $welcomeTemplate = \App\Models\WhatsAppTemplate::withTrashed()
            ->where('waba_account_id', $wabaAccount->id)
            ->where('name', 'LIKE', 'welcome_message%') // Match welcome_message, welcome_message_2, etc.
            ->where('language', 'fr')
            ->first();

        if ($welcomeTemplate) {
            // ✅ If soft-deleted, restore it
            if ($welcomeTemplate->trashed()) {
                $welcomeTemplate->restore();
                Log::info('Restored soft-deleted welcome template for WABA', [
                    'waba_id' => $wabaAccount->id,
                    'template_name' => $welcomeTemplate->name
                ]);
            } else {
                Log::info('Welcome template already exists for WABA', [
                    'waba_id' => $wabaAccount->id,
                    'template_name' => $welcomeTemplate->name
                ]);
            }
        } else {
            // Generate unique name if needed
            $uniqueWelcomeName = $this->generateUniqueTemplateName($wabaAccount->business_id, 'welcome_message', 'fr');
            Log::info('Creating welcome template with unique name', [
                'waba_id' => $wabaAccount->id,
                'template_name' => $uniqueWelcomeName
            ]);
            $this->createWelcomeTemplate($wabaAccount, $credentials, $uniqueWelcomeName);
        }

        // ✅ Check for satisfaction template for THIS specific WABA (including soft-deleted)
        $satisfactionTemplate = \App\Models\WhatsAppTemplate::withTrashed()
            ->where('waba_account_id', $wabaAccount->id)
            ->where('name', 'LIKE', 'satisfaction_survey%') // Match satisfaction_survey, satisfaction_survey_2, etc.
            ->where('language', 'fr')
            ->first();

        if ($satisfactionTemplate) {
            // ✅ If soft-deleted, restore it
            if ($satisfactionTemplate->trashed()) {
                $satisfactionTemplate->restore();
                Log::info('Restored soft-deleted satisfaction template for WABA', [
                    'waba_id' => $wabaAccount->id,
                    'template_name' => $satisfactionTemplate->name
                ]);
            } else {
                Log::info('Satisfaction template already exists for WABA', [
                    'waba_id' => $wabaAccount->id,
                    'template_name' => $satisfactionTemplate->name
                ]);
            }
        } else {
            // Generate unique name if needed
            $uniqueSatisfactionName = $this->generateUniqueTemplateName($wabaAccount->business_id, 'satisfaction_survey', 'fr');
            Log::info('Creating satisfaction template with unique name', [
                'waba_id' => $wabaAccount->id,
                'template_name' => $uniqueSatisfactionName
            ]);
            $this->createSatisfactionTemplate($wabaAccount, $credentials, $uniqueSatisfactionName);
        }
    }

    /**
     * Generate unique template name by adding numeric suffix if name already exists
     * in ANY WABA of the same business (Facebook rule: template names must be unique per Business Manager)
     * 
     * @param int $businessId
     * @param string $baseName
     * @param string $language
     * @return string Unique template name (e.g., "welcome_message_2")
     */
    protected function generateUniqueTemplateName(int $businessId, string $baseName, string $language): string
    {
        // ✅ Check if base name exists in ANY WABA of this business (including soft-deleted)
        $existingTemplate = \App\Models\WhatsAppTemplate::withTrashed()
            ->where('business_id', $businessId)
            ->where('name', $baseName)
            ->where('language', $language)
            ->first();

        if (!$existingTemplate) {
            // Base name is available, use it
            return $baseName;
        }

        // Base name exists, find next available number
        $counter = 2;
        while (true) {
            $candidateName = "{$baseName}_{$counter}";

            // ✅ Check including soft-deleted templates
            $exists = \App\Models\WhatsAppTemplate::withTrashed()
                ->where('business_id', $businessId)
                ->where('name', $candidateName)
                ->where('language', $language)
                ->exists();

            if (!$exists) {
                // Found unique name
                Log::info('Generated unique template name', [
                    'business_id' => $businessId,
                    'base_name' => $baseName,
                    'unique_name' => $candidateName
                ]);
                return $candidateName;
            }

            $counter++;

            // Safety check to prevent infinite loop
            if ($counter > 100) {
                Log::error('Failed to generate unique template name after 100 attempts', [
                    'business_id' => $businessId,
                    'base_name' => $baseName
                ]);
                return "{$baseName}_" . time(); // Fallback with timestamp
            }
        }
    }

    /**
     * Create welcome template
     * 
     * @param WabaAccount $wabaAccount
     * @param mixed $credentials
     * @param string $templateName Unique template name (e.g., "welcome_message" or "welcome_message_2")
     */
    protected function createWelcomeTemplate(WabaAccount $wabaAccount, $credentials, string $templateName = 'welcome_message'): void
    {
        try {
            $business = $wabaAccount->business;
            $templateData = [
                'name' => $templateName, // ✅ Use unique name instead of hardcoded 'welcome_message'
                'language' => 'fr',
                'category' => 'MARKETING',
                'components' => [
                    [
                        'type' => 'BODY',
                        'text' => "Bonjour ! {$business->name} vous souhaite la bienvenue. Nous sommes ravis de vous accueillir dans notre communauté."
                    ]
                ]
            ];

            $response = Http::withToken($credentials->access_token)
                ->post("https://graph.facebook.com/v24.0/{$wabaAccount->waba_id}/message_templates", $templateData);

            if ($response->successful()) {
                $responseData = $response->json();

                // ✅ Check if template was soft-deleted and restore it
                $existingTemplate = \App\Models\WhatsAppTemplate::withTrashed()
                    ->where('business_id', $business->id)
                    ->where('waba_account_id', $wabaAccount->id)
                    ->where('name', $templateName)
                    ->where('language', 'fr')
                    ->first();

                if ($existingTemplate && $existingTemplate->trashed()) {
                    // Restore soft-deleted template
                    $existingTemplate->restore();
                    $existingTemplate->update([
                        'display_name' => $templateName === 'welcome_message' ? 'Message de bienvenue' : "Message de bienvenue ({$wabaAccount->name})",
                        'category' => 'MARKETING',
                        'body' => $templateData['components'][0]['text'],
                        'components' => $templateData['components'],
                        'facebook_template_id' => $responseData['id'] ?? null,
                        'facebook_status' => $responseData['status'] ?? 'PENDING',
                        'status' => 'pending',
                        'is_active' => false,
                        'submitted_at' => now()
                    ]);

                    Log::info('Restored soft-deleted welcome template', [
                        'business_id' => $business->id,
                        'waba_id' => $wabaAccount->waba_id,
                        'template_name' => $templateName
                    ]);
                } elseif (!$existingTemplate) {
                    // Create new template only if it doesn't exist
                    \App\Models\WhatsAppTemplate::create([
                        'business_id' => $business->id,
                        'waba_account_id' => $wabaAccount->id,
                        'name' => $templateName,
                        'display_name' => $templateName === 'welcome_message' ? 'Message de bienvenue' : "Message de bienvenue ({$wabaAccount->name})",
                        'language' => 'fr',
                        'category' => 'MARKETING',
                        'body' => $templateData['components'][0]['text'],
                        'components' => $templateData['components'],
                        'facebook_template_id' => $responseData['id'] ?? null,
                        'facebook_status' => $responseData['status'] ?? 'PENDING',
                        'status' => 'pending',
                        'is_active' => false,
                        'submitted_at' => now()
                    ]);

                    Log::info('Welcome template created successfully', [
                        'business_id' => $business->id,
                        'waba_id' => $wabaAccount->waba_id,
                        'template_name' => $templateName,
                        'template_id' => $responseData['id'] ?? null
                    ]);
                } else {
                    // Template already exists (not deleted), just update it
                    $existingTemplate->update([
                        'facebook_template_id' => $responseData['id'] ?? null,
                        'facebook_status' => $responseData['status'] ?? 'PENDING',
                        'status' => 'pending'
                    ]);

                    Log::info('Updated existing welcome template', [
                        'business_id' => $business->id,
                        'template_name' => $templateName
                    ]);
                }
            } else {
                Log::error('Failed to create welcome template', [
                    'business_id' => $business->id,
                    'error' => $response->body()
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Exception creating welcome template', [
                'business_id' => $business->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Create satisfaction survey template
     * 
     * @param WabaAccount $wabaAccount
     * @param mixed $credentials
     * @param string $templateName Unique template name (e.g., "satisfaction_survey" or "satisfaction_survey_2")
     */
    protected function createSatisfactionTemplate(WabaAccount $wabaAccount, $credentials, string $templateName = 'satisfaction_survey'): void
    {
        try {
            $templateData = [
                'name' => $templateName, // ✅ Use unique name instead of hardcoded 'satisfaction_survey'
                'language' => 'fr',
                'category' => 'MARKETING',
                'components' => [
                    [
                        'type' => 'BODY',
                        'text' => "Merci d'avoir utilisé nos services ! Êtes-vous satisfait de notre assistance aujourd'hui ?"
                    ],
                    [
                        'type' => 'BUTTONS',
                        'buttons' => [
                            [
                                'type' => 'QUICK_REPLY',
                                'text' => 'Très satisfait' // CORRECTED: Removed emoji as per API error
                            ],
                            [
                                'type' => 'QUICK_REPLY',
                                'text' => 'Pas vraiment' // CORRECTED: Removed emoji as per API error
                            ]
                        ]
                    ]
                ]
            ];

            $response = Http::withToken($credentials->access_token)
                ->post("https://graph.facebook.com/v24.0/{$wabaAccount->waba_id}/message_templates", $templateData);

            if ($response->successful()) {
                $responseData = $response->json();

                // ✅ Check if template was soft-deleted and restore it
                $existingTemplate = \App\Models\WhatsAppTemplate::withTrashed()
                    ->where('business_id', $wabaAccount->business_id)
                    ->where('waba_account_id', $wabaAccount->id)
                    ->where('name', $templateName)
                    ->where('language', 'fr')
                    ->first();

                if ($existingTemplate && $existingTemplate->trashed()) {
                    // Restore soft-deleted template
                    $existingTemplate->restore();
                    $existingTemplate->update([
                        'display_name' => $templateName === 'satisfaction_survey' ? 'Enquête de satisfaction' : "Enquête de satisfaction ({$wabaAccount->name})",
                        'category' => 'MARKETING',
                        'body' => $templateData['components'][0]['text'],
                        'buttons' => $templateData['components'][1]['buttons'],
                        'components' => $templateData['components'],
                        'facebook_template_id' => $responseData['id'] ?? null,
                        'facebook_status' => $responseData['status'] ?? 'PENDING',
                        'status' => 'pending',
                        'is_active' => false,
                        'submitted_at' => now()
                    ]);

                    Log::info('Restored soft-deleted satisfaction template', [
                        'business_id' => $wabaAccount->business_id,
                        'waba_id' => $wabaAccount->waba_id,
                        'template_name' => $templateName
                    ]);
                } elseif (!$existingTemplate) {
                    // Create new template only if it doesn't exist
                    \App\Models\WhatsAppTemplate::create([
                        'business_id' => $wabaAccount->business_id,
                        'waba_account_id' => $wabaAccount->id,
                        'name' => $templateName,
                        'display_name' => $templateName === 'satisfaction_survey' ? 'Enquête de satisfaction' : "Enquête de satisfaction ({$wabaAccount->name})",
                        'language' => 'fr',
                        'category' => 'MARKETING',
                        'body' => $templateData['components'][0]['text'],
                        'buttons' => $templateData['components'][1]['buttons'],
                        'components' => $templateData['components'],
                        'facebook_template_id' => $responseData['id'] ?? null,
                        'facebook_status' => $responseData['status'] ?? 'PENDING',
                        'status' => 'pending',
                        'is_active' => false,
                        'submitted_at' => now()
                    ]);

                    Log::info('Satisfaction template created successfully', [
                        'business_id' => $wabaAccount->business_id,
                        'waba_id' => $wabaAccount->waba_id,
                        'template_name' => $templateName,
                        'template_id' => $responseData['id'] ?? null
                    ]);
                } else {
                    // Template already exists (not deleted), just update it
                    $existingTemplate->update([
                        'facebook_template_id' => $responseData['id'] ?? null,
                        'facebook_status' => $responseData['status'] ?? 'PENDING',
                        'status' => 'pending'
                    ]);

                    Log::info('Updated existing satisfaction template', [
                        'business_id' => $wabaAccount->business_id,
                        'template_name' => $templateName
                    ]);
                }
            } else {
                Log::error('Failed to create satisfaction template', [
                    'business_id' => $wabaAccount->business_id,
                    'error' => $response->body()
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Exception creating satisfaction template', [
                'business_id' => $wabaAccount->business_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Soft delete phone numbers that no longer exist in Facebook
     */
    protected function cleanupOrphanedPhoneNumbers(Business $business, array $facebookPhoneIds): void
    {
        try {
            Log::info('Starting cleanup of orphaned phone numbers', [
                'business_id' => $business->id,
                'facebook_phone_ids' => $facebookPhoneIds,
                'facebook_phone_count' => count($facebookPhoneIds)
            ]);

            // Get all local phone numbers for this business
            $localPhoneNumbers = \App\Models\WhatsAppPhoneNumber::where('business_id', $business->id)
                ->whereNotNull('phone_number_id') // Only check numbers that have Facebook IDs
                ->get();

            $orphanedCount = 0;
            $orphanedNumbers = [];

            foreach ($localPhoneNumbers as $localPhone) {
                // If local phone number ID is not in Facebook response, it's orphaned
                if (!in_array($localPhone->phone_number_id, $facebookPhoneIds)) {
                    Log::info('Found orphaned phone number', [
                        'business_id' => $business->id,
                        'local_phone_id' => $localPhone->id,
                        'facebook_phone_id' => $localPhone->phone_number_id,
                        'display_phone_number' => $localPhone->display_phone_number
                    ]);

                    // Soft delete the orphaned phone number
                    $localPhone->delete();
                    $orphanedCount++;
                    $orphanedNumbers[] = $localPhone->display_phone_number;

                    Log::info('Soft deleted orphaned phone number', [
                        'business_id' => $business->id,
                        'phone_number_id' => $localPhone->phone_number_id,
                        'display_phone_number' => $localPhone->display_phone_number
                    ]);
                }
            }

            Log::info('Cleanup completed', [
                'business_id' => $business->id,
                'orphaned_count' => $orphanedCount,
                'orphaned_numbers' => $orphanedNumbers,
                'remaining_active_count' => count($facebookPhoneIds)
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to cleanup orphaned phone numbers', [
                'business_id' => $business->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Subscribe WABA to receive webhook messages
     * According to Facebook documentation: POST /{WABA_ID}/subscribed_apps
     * Based on StackOverflow recommendations for proper WABA webhook subscription
     */
    public function subscribeWabaToWebhooks(Business $business, string $wabaId): array
    {
        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            return [
                'success' => false,
                'error' => 'No active Facebook credentials found'
            ];
        }

        try {
            Log::info('Subscribing WABA to webhooks', [
                'business_id' => $business->id,
                'waba_id' => $wabaId
            ]);

            // Subscribe WABA to app webhooks using the recommended endpoint
            // This subscribes the WhatsApp Business Account to your app
            $response = Http::withToken($credentials->access_token)
                ->post("https://graph.facebook.com/v24.0/{$wabaId}/subscribed_apps");

            if ($response->failed()) {
                $error = 'Failed to subscribe WABA to webhooks: ' . $response->body();
                Log::error($error, [
                    'business_id' => $business->id,
                    'waba_id' => $wabaId,
                    'response_status' => $response->status(),
                    'response_body' => $response->body()
                ]);

                return [
                    'success' => false,
                    'error' => $error,
                    'facebook_error' => $response->json()
                ];
            }

            $responseData = $response->json();

            // Verify the subscription was successful
            if (!isset($responseData['success']) || !$responseData['success']) {
                Log::warning('WABA subscription response indicates failure', [
                    'business_id' => $business->id,
                    'waba_id' => $wabaId,
                    'response' => $responseData
                ]);

                return [
                    'success' => false,
                    'error' => 'WABA subscription failed - Facebook API returned unsuccessful response',
                    'facebook_response' => $responseData
                ];
            }

            $confirmWabaWebhookSubscriptionResult = $this->confirmWabaWebhookSubscription($business, $wabaId);
            WabaAccount::where('waba_id', $wabaId)->update(['webhook_subscription_status' => 'subscribed', 'webhook_subscription_data' => $confirmWabaWebhookSubscriptionResult['data'], 'webhook_subscribed_at' => now(), 'webhook_subscribed' => true]);
            Log::info('WABA subscribed to webhooks successfully', [
                'business_id' => $business->id,
                'waba_id' => $wabaId,
                'response' => $responseData
            ]);
            return [
                'success' => true,
                'data' => $confirmWabaWebhookSubscriptionResult,
                'message' => 'WABA subscribed to receive webhook messages successfully'
            ];
        } catch (\Exception $e) {
            Log::error('WABA webhook subscription failed', [
                'business_id' => $business->id,
                'waba_id' => $wabaId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Webhook subscription failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Unsubscribe WABA from webhooks
     */
    public function unsubscribeWabaFromWebhooks(Business $business, string $wabaId): array
    {
        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            return [
                'success' => false,
                'error' => 'No active Facebook credentials found'
            ];
        }

        try {
            Log::info('Unsubscribing WABA from webhooks', [
                'business_id' => $business->id,
                'waba_id' => $wabaId
            ]);

            // Unsubscribe WABA from app webhooks using DELETE
            $response = Http::withToken($credentials->access_token)
                ->delete("https://graph.facebook.com/v24.0/{$wabaId}/subscribed_apps");

            if ($response->failed()) {
                $error = 'Failed to unsubscribe WABA from webhooks: ' . $response->body();
                Log::error($error, [
                    'business_id' => $business->id,
                    'waba_id' => $wabaId,
                    'response_status' => $response->status(),
                    'response_body' => $response->body()
                ]);

                return [
                    'success' => false,
                    'error' => $error,
                    'facebook_error' => $response->json()
                ];
            }

            $responseData = $response->json();

            Log::info('WABA unsubscribed from webhooks successfully', [
                'business_id' => $business->id,
                'waba_id' => $wabaId,
                'response' => $responseData
            ]);

            return [
                'success' => true,
                'data' => $responseData,
                'message' => 'WABA unsubscribed from webhooks successfully'
            ];
        } catch (\Exception $e) {
            Log::error('WABA webhook unsubscription failed', [
                'business_id' => $business->id,
                'waba_id' => $wabaId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Webhook unsubscription failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Deregister phone number from WhatsApp Business API
     */
    public function deregisterPhoneNumber(Business $business, string $phoneNumberId): array
    {
        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            return ['success' => false, 'error' => 'No active Facebook credentials found'];
        }

        try {
            Log::info('Deregistering phone number from WhatsApp', [
                'business_id' => $business->id,
                'phone_number_id' => $phoneNumberId
            ]);

            // Deregister phone number - removes it from WhatsApp Business API
            $response = Http::withToken($credentials->access_token)
                ->post("https://graph.facebook.com/v24.0/{$phoneNumberId}/deregister", [
                    'messaging_product' => 'whatsapp'
                ]);

            if ($response->failed()) {
                $errorBody = $response->json();
                Log::error('Failed to deregister phone number', [
                    'phone_number_id' => $phoneNumberId,
                    'response_status' => $response->status(),
                    'response_body' => $response->body(),
                    'facebook_error' => $errorBody
                ]);

                $errorMessage = $errorBody['error']['message'] ?? 'Failed to deregister phone number';
                return ['success' => false, 'error' => $errorMessage];
            }

            $responseData = $response->json();
            Log::info('Phone number deregistered successfully', [
                'phone_number_id' => $phoneNumberId,
                'response' => $responseData
            ]);

            return ['success' => true, 'data' => $responseData];
        } catch (\Exception $e) {
            Log::error('Exception deregistering phone number', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Upload media to Facebook WhatsApp API
     * Based on: https://developers.facebook.com/docs/whatsapp/cloud-api/reference/media#upload-media
     */
    public function uploadMedia(int $businessId, string $phoneNumberId, $file, string $mediaType): array
    {
        $business = \App\Models\Business::find($businessId);
        if (!$business) {
            return [
                'success' => false,
                'error' => 'Business not found'
            ];
        }

        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            return [
                'success' => false,
                'error' => 'No active Facebook credentials found'
            ];
        }

        try {
            $originalMimeType = $file->getMimeType();
            $fileToUpload = $file;
            $fileName = $file->getClientOriginalName();
            $tempConvertedPath = null;

            Log::info('Uploading media to Facebook', [
                'business_id' => $businessId,
                'phone_number_id' => $phoneNumberId,
                'media_type' => $mediaType,
                'file_size' => $file->getSize(),
                'mime_type' => $originalMimeType
            ]);

            // ✅ Normalize audio MIME types for Facebook compatibility
            if ($mediaType === 'audio') {
                // Fix -1: video/webm → OGG/Opus (Chrome sometimes records audio as video/webm)
                if (strpos($originalMimeType, 'video/webm') !== false) {
                    Log::info('Detected video/webm for audio - converting to OGG', [
                        'original_mime' => $originalMimeType,
                        'file_size' => $file->getSize(),
                        'reason' => 'Browser created video/webm container for audio recording'
                    ]);

                    $tempConvertedPath = $this->convertWebmToOgg($file);

                    if ($tempConvertedPath && file_exists($tempConvertedPath)) {
                        $fileToUpload = new \Illuminate\Http\UploadedFile(
                            $tempConvertedPath,
                            pathinfo($tempConvertedPath, PATHINFO_BASENAME),
                            'audio/ogg',
                            null,
                            true
                        );

                        $fileName = pathinfo($tempConvertedPath, PATHINFO_BASENAME);

                        Log::info('✅ video/webm converted to audio/ogg successfully', [
                            'converted_file' => $fileName,
                            'converted_size' => filesize($tempConvertedPath)
                        ]);
                    } else {
                        Log::warning('⚠️ Conversion failed, cannot send video/webm as audio', [
                            'original_mime' => $originalMimeType
                        ]);

                        return [
                            'success' => false,
                            'error' => 'Audio conversion failed. FFmpeg may not be installed on the server.'
                        ];
                    }
                }
                // Fix 0: video/mp4 → OGG/Opus (Safari/iOS records audio as video/mp4)
                // ⚠️ Facebook rejects video/mp4 for audio, even if we say audio/mp4
                // Solution: Convert to OGG/Opus using FFmpeg
                if ($originalMimeType === 'video/mp4' || strpos($originalMimeType, 'video/mp4') !== false) {
                    Log::info('Detected video/mp4 for audio - converting to OGG', [
                        'original_mime' => $originalMimeType,
                        'file_size' => $file->getSize(),
                        'reason' => 'Safari/iOS MediaRecorder creates video/mp4 containers for audio'
                    ]);

                    $tempConvertedPath = $this->convertWebmToOgg($file);

                    if ($tempConvertedPath && file_exists($tempConvertedPath)) {
                        // Use converted OGG file
                        $fileToUpload = new \Illuminate\Http\UploadedFile(
                            $tempConvertedPath,
                            pathinfo($tempConvertedPath, PATHINFO_BASENAME),
                            'audio/ogg',
                            null,
                            true
                        );

                        $fileName = pathinfo($tempConvertedPath, PATHINFO_BASENAME);

                        Log::info('✅ video/mp4 converted to audio/ogg successfully', [
                            'converted_file' => $fileName,
                            'converted_size' => filesize($tempConvertedPath)
                        ]);
                    } else {
                        Log::warning('⚠️ Conversion failed, will try to send as audio/mp4 (might fail)', [
                            'original_mime' => $originalMimeType
                        ]);

                        // Fallback: Try with audio/mp4 anyway (might fail)
                        $fileToUpload = new \Illuminate\Http\UploadedFile(
                            $file->getRealPath(),
                            $fileName,
                            'audio/mp4',
                            $file->getError(),
                            true
                        );
                    }
                }
                // Fix 1: Convert WebM/WebM-Video audio to OGG (Opus) - WhatsApp compatible
                elseif (strpos($originalMimeType, 'webm') !== false) {
                    Log::info('Converting WebM to OGG format', [
                        'original_mime' => $originalMimeType,
                        'original_size' => $file->getSize(),
                        'reason' => 'WebM not fully supported by WhatsApp, converting to OGG/Opus'
                    ]);

                    $tempConvertedPath = $this->convertWebmToOgg($file);

                    if ($tempConvertedPath && file_exists($tempConvertedPath)) {
                        // Create a new UploadedFile instance for the converted file
                        $fileToUpload = new \Illuminate\Http\UploadedFile(
                            $tempConvertedPath,
                            pathinfo($tempConvertedPath, PATHINFO_BASENAME),
                            'audio/ogg',
                            null,
                            true // test mode
                        );

                        $fileName = pathinfo($tempConvertedPath, PATHINFO_BASENAME);

                        Log::info('Audio converted successfully', [
                            'converted_file' => $fileName,
                            'converted_size' => filesize($tempConvertedPath),
                            'mime_type' => 'audio/ogg'
                        ]);
                    } else {
                        Log::warning('Audio conversion failed, using original file', [
                            'original_mime' => $originalMimeType
                        ]);
                    }
                }

                // Fix 2: Normalize audio/x-m4a to audio/mp4 (Facebook doesn't accept x-m4a)
                elseif (strpos($originalMimeType, 'x-m4a') !== false || $originalMimeType === 'audio/x-m4a') {
                    Log::info('Normalizing audio/x-m4a to audio/mp4', [
                        'original_mime' => $originalMimeType,
                        'file_size' => $file->getSize()
                    ]);

                    // Create a new UploadedFile instance with corrected MIME type
                    $fileToUpload = new \Illuminate\Http\UploadedFile(
                        $file->getRealPath(),
                        $fileName,
                        'audio/mp4', // ✅ Facebook-compatible MIME type
                        $file->getError(),
                        true // test mode
                    );

                    Log::info('MIME type normalized successfully', [
                        'file_name' => $fileName,
                        'old_mime' => $originalMimeType,
                        'new_mime' => 'audio/mp4'
                    ]);
                }

                // Fix 3: Normalize audio/x-wav to audio/wav
                elseif (strpos($originalMimeType, 'x-wav') !== false || $originalMimeType === 'audio/x-wav') {
                    Log::info('Normalizing audio/x-wav to audio/wav', [
                        'original_mime' => $originalMimeType
                    ]);

                    $fileToUpload = new \Illuminate\Http\UploadedFile(
                        $file->getRealPath(),
                        $fileName,
                        'audio/wav',
                        $file->getError(),
                        true
                    );
                }
            }

            // Prepare the file for upload
            // ✅ Get the MIME type from fileToUpload (after normalization)
            $finalMimeType = $fileToUpload->getMimeType();

            Log::info('Uploading to Facebook with final MIME type', [
                'original_mime' => $originalMimeType,
                'final_mime' => $finalMimeType,
                'file_name' => $fileName
            ]);

            // ✅ CRITICAL FIX: Attach file with correct Content-Type
            $response = Http::withToken($credentials->access_token)
                ->attach(
                    'file',
                    file_get_contents($fileToUpload->getRealPath()),
                    $fileName,
                    ['Content-Type' => $finalMimeType]
                )
                ->post("https://graph.facebook.com/v24.0/{$phoneNumberId}/media", [
                    'messaging_product' => 'whatsapp'
                ]);

            // Clean up temporary converted file
            if ($tempConvertedPath && file_exists($tempConvertedPath)) {
                @unlink($tempConvertedPath);
            }

            if ($response->failed()) {
                $error = 'Failed to upload media: ' . $response->body();
                Log::error($error, [
                    'business_id' => $businessId,
                    'phone_number_id' => $phoneNumberId,
                    'response_status' => $response->status(),
                    'response_body' => $response->body()
                ]);

                return [
                    'success' => false,
                    'error' => $error,
                    'facebook_error' => $response->json()
                ];
            }

            try {
                \App\Models\FacebookApiUsage::parseAndStore(
                    $business->id,
                    $response->headers(),
                    $phoneNumberId
                );
            } catch (\Exception $e) {
                Log::warning('Failed to capture Facebook API rate limit headers', [
                    'error' => $e->getMessage(),
                    'business_id' => $business->id
                ]);
            }

            $responseData = $response->json();

            Log::info('Media uploaded successfully', [
                'business_id' => $businessId,
                'phone_number_id' => $phoneNumberId,
                'media_id' => $responseData['id'] ?? 'unknown',
                'response' => $responseData
            ]);

            return [
                'success' => true,
                'media_id' => $responseData['id'],
                'data' => $responseData
            ];
        } catch (\Exception $e) {
            Log::error('Media upload failed', [
                'business_id' => $businessId,
                'phone_number_id' => $phoneNumberId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => 'Media upload failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Convert WebM audio to OGG (Opus) format using FFmpeg
     * WhatsApp only accepts: audio/aac, audio/mp4, audio/mpeg, audio/amr, audio/ogg, audio/opus
     */
    /**
     * Convert audio to OGG/Opus format (WhatsApp compatible)
     * Supports: WebM, MP4, WAV, and other formats
     */
    private function convertWebmToOgg($file): ?string
    {
        try {
            // Check if FFmpeg is available
            $ffmpegPath = trim(shell_exec('which ffmpeg') ?? '');

            if (empty($ffmpegPath)) {
                Log::warning('FFmpeg not found, cannot convert audio to OGG');
                return null;
            }

            $inputPath = $file->getRealPath();
            $outputPath = sys_get_temp_dir() . '/audio_' . time() . '_' . uniqid() . '.ogg';

            // FFmpeg command to convert ANY audio format to OGG (Opus codec)
            // -c:a libopus: Use Opus audio codec (WhatsApp compatible)
            // -b:a 128k: Audio bitrate (good quality for voice)
            // -vn: No video (strips video track if present in MP4)
            // -y: Overwrite output file
            $command = sprintf(
                '%s -i %s -c:a libopus -b:a 128k -vn -y %s 2>&1',
                escapeshellarg($ffmpegPath),
                escapeshellarg($inputPath),
                escapeshellarg($outputPath)
            );

            Log::info('Converting audio to OGG with FFmpeg', [
                'input_mime' => $file->getMimeType(),
                'input_path' => $inputPath,
                'output_path' => $outputPath
            ]);

            exec($command, $output, $returnCode);

            if ($returnCode === 0 && file_exists($outputPath) && filesize($outputPath) > 0) {
                Log::info('✅ FFmpeg audio conversion successful', [
                    'output_file' => $outputPath,
                    'input_size' => $file->getSize(),
                    'output_size' => filesize($outputPath),
                    'compression_ratio' => round((1 - filesize($outputPath) / $file->getSize()) * 100, 2) . '%'
                ]);
                return $outputPath;
            } else {
                Log::error('❌ FFmpeg conversion failed', [
                    'return_code' => $returnCode,
                    'output' => implode("\n", $output)
                ]);

                // Clean up failed output file
                if (file_exists($outputPath)) {
                    @unlink($outputPath);
                }

                return null;
            }
        } catch (\Exception $e) {
            Log::error('Error during audio conversion', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Send image message
     * Based on: https://developers.facebook.com/docs/whatsapp/cloud-api/messages/image-messages/
     */
    public function sendImageMessage(int $businessId, string $phoneNumberId, string $recipientPhone, string $mediaId, ?string $caption = null, ?string $conversationUuid = null, ?int $fileSize = null, ?string $localPath = null): array
    {
        $business = \App\Models\Business::find($businessId);
        if (!$business) {
            return [
                'success' => false,
                'error' => 'Business not found'
            ];
        }

        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            return [
                'success' => false,
                'error' => 'No active Facebook credentials found'
            ];
        }

        try {
            $messageData = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $recipientPhone,
                'type' => 'image',
                'image' => [
                    'id' => $mediaId
                ]
            ];

            if ($caption) {
                $messageData['image']['caption'] = $caption;
            }

            Log::info('Sending image message', [
                'business_id' => $businessId,
                'phone_number_id' => $phoneNumberId,
                'recipient' => $recipientPhone,
                'media_id' => $mediaId,
                'has_caption' => !empty($caption)
            ]);

            $response = Http::withToken($credentials->access_token)
                ->post("https://graph.facebook.com/v24.0/{$phoneNumberId}/messages", $messageData);

            if ($response->failed()) {
                $error = 'Failed to send image message: ' . $response->body();
                Log::error($error, [
                    'business_id' => $businessId,
                    'phone_number_id' => $phoneNumberId,
                    'response_status' => $response->status(),
                    'response_body' => $response->body()
                ]);

                return [
                    'success' => false,
                    'error' => $error,
                    'facebook_error' => $response->json()
                ];
            }

            $responseData = $response->json();

            // ✅ Capturer les headers de rate limit API
            try {
                \App\Models\FacebookApiUsage::parseAndStore(
                    $businessId,
                    $response->headers(),
                    $phoneNumberId
                );
            } catch (\Exception $e) {
                Log::warning('Failed to capture Facebook API rate limit headers', [
                    'error' => $e->getMessage(),
                    'business_id' => $businessId
                ]);
            }

            // Save message to database with file size and local path
            $additionalData = [
                'media_id' => $mediaId,
                'media_type' => 'image'
            ];
            if ($fileSize) {
                $additionalData['media_file_size'] = $fileSize;
            }
            if ($localPath) {
                $additionalData['local_media_path'] = $localPath;
                $additionalData['media_url'] = Storage::disk('public')->url($localPath);
            }
            $this->saveOutgoingMessage($business, $phoneNumberId, $recipientPhone, 'image', ['caption' => $caption ?? '[Image]'], $responseData, $conversationUuid, $additionalData);

            Log::info('Image message sent successfully', [
                'business_id' => $businessId,
                'phone_number_id' => $phoneNumberId,
                'message_id' => $responseData['messages'][0]['id'] ?? 'unknown'
            ]);

            return [
                'success' => true,
                'message_id' => $responseData['messages'][0]['id'] ?? null,
                'data' => $responseData
            ];
        } catch (\Exception $e) {
            Log::error('Image message sending failed', [
                'business_id' => $businessId,
                'phone_number_id' => $phoneNumberId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Image message sending failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Send document message
     * Based on: https://developers.facebook.com/docs/whatsapp/cloud-api/messages/document-messages
     */
    public function sendDocumentMessage(int $businessId, string $phoneNumberId, string $recipientPhone, string $mediaId, string $filename, ?string $caption = null, ?string $conversationUuid = null, ?int $fileSize = null, ?string $localPath = null): array
    {
        $business = \App\Models\Business::find($businessId);
        if (!$business) {
            return [
                'success' => false,
                'error' => 'Business not found'
            ];
        }

        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            return [
                'success' => false,
                'error' => 'No active Facebook credentials found'
            ];
        }

        try {
            $messageData = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $recipientPhone,
                'type' => 'document',
                'document' => [
                    'id' => $mediaId,
                    'filename' => $filename
                ]
            ];

            if ($caption) {
                $messageData['document']['caption'] = $caption;
            }

            Log::info('Sending document message', [
                'business_id' => $businessId,
                'phone_number_id' => $phoneNumberId,
                'recipient' => $recipientPhone,
                'media_id' => $mediaId,
                'filename' => $filename,
                'has_caption' => !empty($caption)
            ]);

            $response = Http::withToken($credentials->access_token)
                ->post("https://graph.facebook.com/v24.0/{$phoneNumberId}/messages", $messageData);

            if ($response->failed()) {
                $error = 'Failed to send document message: ' . $response->body();
                Log::error($error, [
                    'business_id' => $businessId,
                    'phone_number_id' => $phoneNumberId,
                    'response_status' => $response->status(),
                    'response_body' => $response->body()
                ]);

                return [
                    'success' => false,
                    'error' => $error,
                    'facebook_error' => $response->json()
                ];
            }

            $responseData = $response->json();

            // ✅ Capturer les headers de rate limit API
            try {
                \App\Models\FacebookApiUsage::parseAndStore(
                    $businessId,
                    $response->headers(),
                    $phoneNumberId
                );
            } catch (\Exception $e) {
                Log::warning('Failed to capture Facebook API rate limit headers', [
                    'error' => $e->getMessage(),
                    'business_id' => $businessId
                ]);
            }

            // Save message to database with file size and local path
            $additionalData = [
                'media_id' => $mediaId,
                'media_type' => 'document',
                'media_filename' => $filename
            ];
            if ($fileSize) {
                $additionalData['media_file_size'] = $fileSize;
            }
            if ($localPath) {
                $additionalData['local_media_path'] = $localPath;
                $additionalData['media_url'] = Storage::disk('public')->url($localPath);
            }
            $this->saveOutgoingMessage($business, $phoneNumberId, $recipientPhone, 'document', ['caption' => $caption ?? "[Document: {$filename}]"], $responseData, $conversationUuid, $additionalData);

            Log::info('Document message sent successfully', [
                'business_id' => $businessId,
                'phone_number_id' => $phoneNumberId,
                'message_id' => $responseData['messages'][0]['id'] ?? 'unknown'
            ]);

            return [
                'success' => true,
                'message_id' => $responseData['messages'][0]['id'] ?? null,
                'data' => $responseData
            ];
        } catch (\Exception $e) {
            Log::error('Document message sending failed', [
                'business_id' => $businessId,
                'phone_number_id' => $phoneNumberId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Document message sending failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Send audio message
     * Based on: https://developers.facebook.com/docs/whatsapp/cloud-api/messages/audio-messages
     */
    public function sendAudioMessage(int $businessId, string $phoneNumberId, string $recipientPhone, string $mediaId, ?string $conversationUuid = null, ?int $fileSize = null, ?string $localPath = null): array
    {
        $business = \App\Models\Business::find($businessId);
        if (!$business) {
            return [
                'success' => false,
                'error' => 'Business not found'
            ];
        }

        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            return [
                'success' => false,
                'error' => 'No active Facebook credentials found'
            ];
        }

        try {
            $messageData = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $recipientPhone,
                'type' => 'audio',
                'audio' => [
                    'id' => $mediaId
                ]
            ];

            Log::info('Sending audio message', [
                'business_id' => $businessId,
                'phone_number_id' => $phoneNumberId,
                'recipient' => $recipientPhone,
                'media_id' => $mediaId
            ]);

            $response = Http::withToken($credentials->access_token)
                ->post("https://graph.facebook.com/v24.0/{$phoneNumberId}/messages", $messageData);

            if ($response->failed()) {
                $error = 'Failed to send audio message: ' . $response->body();
                Log::error($error, [
                    'business_id' => $businessId,
                    'phone_number_id' => $phoneNumberId,
                    'response_status' => $response->status(),
                    'response_body' => $response->body()
                ]);

                return [
                    'success' => false,
                    'error' => $error,
                    'facebook_error' => $response->json()
                ];
            }

            $responseData = $response->json();

            // ✅ Capturer les headers de rate limit API
            try {
                \App\Models\FacebookApiUsage::parseAndStore(
                    $businessId,
                    $response->headers(),
                    $phoneNumberId
                );
            } catch (\Exception $e) {
                Log::warning('Failed to capture Facebook API rate limit headers', [
                    'error' => $e->getMessage(),
                    'business_id' => $businessId
                ]);
            }

            // Save message to database with file size and local path
            $additionalData = [
                'media_id' => $mediaId,
                'media_type' => 'audio'
            ];
            if ($fileSize) {
                $additionalData['media_file_size'] = $fileSize;
            }
            if ($localPath) {
                $additionalData['local_media_path'] = $localPath;
                $additionalData['media_url'] = Storage::disk('public')->url($localPath);
            }
            $this->saveOutgoingMessage($business, $phoneNumberId, $recipientPhone, 'audio', ['content' => '[Audio Message]'], $responseData, $conversationUuid, $additionalData);

            Log::info('Audio message sent successfully', [
                'business_id' => $businessId,
                'phone_number_id' => $phoneNumberId,
                'message_id' => $responseData['messages'][0]['id'] ?? 'unknown'
            ]);

            return [
                'success' => true,
                'message_id' => $responseData['messages'][0]['id'] ?? null,
                'data' => $responseData
            ];
        } catch (\Exception $e) {
            Log::error('Audio message sending failed', [
                'business_id' => $businessId,
                'phone_number_id' => $phoneNumberId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Audio message sending failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Send location message via WhatsApp Business API
     */
    public function sendLocationMessage(Business $business, string $phoneNumberId, string $waId, float $latitude, float $longitude, string $name = '', string $address = '', ?string $conversationUuid = null): array
    {
        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            return [
                'success' => false,
                'error' => 'No active Facebook credentials found'
            ];
        }

        try {
            // Prepare location message data
            $messageData = [
                'messaging_product' => 'whatsapp',
                'to' => $waId,
                'type' => 'location',
                'location' => [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                ]
            ];

            // Add name and address if provided
            if (!empty($name)) {
                $messageData['location']['name'] = $name;
            }
            if (!empty($address)) {
                $messageData['location']['address'] = $address;
            }

            Log::info('Sending location message', [
                'business_id' => $business->id,
                'phone_number_id' => $phoneNumberId,
                'wa_id' => $waId,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'name' => $name,
                'address' => $address
            ]);

            // Send message via Facebook API
            $response = Http::withToken($credentials->access_token)
                ->post("https://graph.facebook.com/v24.0/{$phoneNumberId}/messages", $messageData);

            if ($response->failed()) {
                $error = 'Failed to send location message: ' . $response->body();
                Log::error($error, [
                    'business_id' => $business->id,
                    'phone_number_id' => $phoneNumberId,
                    'wa_id' => $waId,
                    'response_status' => $response->status(),
                    'response_body' => $response->body()
                ]);

                return [
                    'success' => false,
                    'error' => $error,
                    'response' => $response->json()
                ];
            }

            $responseData = $response->json();
            $messageId = $responseData['messages'][0]['id'] ?? null;

            // ✅ Capturer les headers de rate limit API
            try {
                \App\Models\FacebookApiUsage::parseAndStore(
                    $business->id,
                    $response->headers(),
                    $phoneNumberId
                );
            } catch (\Exception $e) {
                Log::warning('Failed to capture Facebook API rate limit headers', [
                    'error' => $e->getMessage(),
                    'business_id' => $business->id
                ]);
            }

            Log::info('Location message sent successfully', [
                'business_id' => $business->id,
                'phone_number_id' => $phoneNumberId,
                'wa_id' => $waId,
                'message_id' => $messageId
            ]);

            // Save message to WhatsAppMessage table
            $this->saveOutgoingMessage($business, $phoneNumberId, $waId, 'location', [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'location_name' => $name,
                'location_address' => $address
            ], $responseData, $conversationUuid);

            return [
                'success' => true,
                'message_id' => $messageId,
                'response' => $responseData
            ];
        } catch (\Exception $e) {
            Log::error('Location message sending failed', [
                'business_id' => $business->id,
                'phone_number_id' => $phoneNumberId,
                'wa_id' => $waId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Location message sending failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Confirm WABA webhook subscription status
     * According to StackOverflow recommendations: GET /{WABA_ID}/subscribed_apps
     * This confirms the subscription by calling the same endpoint with GET
     */
    public function confirmWabaWebhookSubscription(Business $business, string $wabaId): array
    {
        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            return [
                'success' => false,
                'error' => 'No active Facebook credentials found'
            ];
        }

        try {
            Log::info('Confirming WABA webhook subscription status', [
                'business_id' => $business->id,
                'waba_id' => $wabaId
            ]);

            // Confirm subscription by calling the same endpoint with GET
            $response = Http::withToken($credentials->access_token)
                ->get("https://graph.facebook.com/v24.0/{$wabaId}/subscribed_apps");

            if ($response->failed()) {
                $error = 'Failed to confirm WABA webhook subscription: ' . $response->body();
                Log::error($error, [
                    'business_id' => $business->id,
                    'waba_id' => $wabaId,
                    'response_status' => $response->status(),
                    'response_body' => $response->body()
                ]);

                return [
                    'success' => false,
                    'error' => $error,
                    'facebook_error' => $response->json()
                ];
            }

            $responseData = $response->json();

            // Check if the WABA is subscribed to any apps
            $isSubscribed = isset($responseData['data']) &&
                is_array($responseData['data']) &&
                count($responseData['data']) > 0;

            Log::info('WABA webhook subscription status confirmed', [
                'business_id' => $business->id,
                'waba_id' => $wabaId,
                'is_subscribed' => $isSubscribed,
                'subscribed_apps_count' => $isSubscribed ? count($responseData['data']) : 0,
                'response' => $responseData
            ]);

            return [
                'success' => true,
                'is_subscribed' => $isSubscribed,
                'data' => $responseData,
                'subscribed_apps' => $responseData['data'] ?? [],
                'message' => $isSubscribed ?
                    'WABA is subscribed to webhook apps' :
                    'WABA is not subscribed to any webhook apps'
            ];
        } catch (\Exception $e) {
            Log::error('WABA webhook subscription confirmation failed', [
                'business_id' => $business->id,
                'waba_id' => $wabaId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Webhook subscription confirmation failed: ' . $e->getMessage()
            ];
        }
    }


    /**
     * Check webhook subscription status for phone number
     */
    public function checkWebhookSubscription(Business $business, string $phoneNumberId): array
    {
        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            return [
                'success' => false,
                'error' => 'No active Facebook credentials found'
            ];
        }

        try {
            $response = Http::withToken($credentials->access_token)
                ->get("https://graph.facebook.com/v24.0/{$phoneNumberId}/subscribed_apps");

            if ($response->failed()) {
                return [
                    'success' => false,
                    'error' => 'Failed to check webhook subscription: ' . $response->body()
                ];
            }

            $subscriptionData = $response->json();
            $isSubscribed = isset($subscriptionData['data']) &&
                collect($subscriptionData['data'])->contains(function ($app) {
                    return in_array('messages', $app['subscribed_fields'] ?? []);
                });

            return [
                'success' => true,
                'is_subscribed' => $isSubscribed,
                'data' => $subscriptionData
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Create message template on Facebook
     */
    public function createMessageTemplate(int $businessId, array $templateData): array
    {
        $business = \App\Models\Business::find($businessId);
        if (!$business) {
            return [
                'success' => false,
                'error' => 'Business not found'
            ];
        }

        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            return [
                'success' => false,
                'error' => 'No active Facebook credentials found'
            ];
        }

        try {
            // Use the specific WABA account that was selected in the form
            $wabaAccountId = $templateData['waba_account_id'] ?? null;
            if (!$wabaAccountId) {
                return [
                    'success' => false,
                    'error' => 'WABA account ID not provided'
                ];
            }

            $wabaAccount = $business->wabaAccounts()->find($wabaAccountId);
            if (!$wabaAccount) {
                return [
                    'success' => false,
                    'error' => 'Selected WABA account not found or not accessible'
                ];
            }

            Log::info('Creating template on Facebook', [
                'business_id' => $businessId,
                'waba_id' => $wabaAccount->waba_id,
                'template_name' => $templateData['name']
            ]);

            $response = Http::withToken($credentials->access_token)
                ->post("https://graph.facebook.com/v24.0/{$wabaAccount->waba_id}/message_templates", $templateData);

            if ($response->failed()) {
                $error = 'Failed to create template: ' . $response->body();
                Log::error($error, [
                    'business_id' => $businessId,
                    'template_data' => $templateData
                ]);

                return [
                    'success' => false,
                    'error' => $error,
                    'facebook_error' => $response->json()
                ];
            }

            $responseData = $response->json();

            Log::info('Template created successfully on Facebook', [
                'business_id' => $businessId,
                'template_id' => $responseData['id'] ?? null,
                'status' => $responseData['status'] ?? null
            ]);

            return [
                'success' => true,
                'data' => $responseData
            ];
        } catch (\Exception $e) {
            Log::error('Template creation failed', [
                'business_id' => $businessId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Delete message template from Facebook
     */
    public function deleteMessageTemplate(int $businessId, string $facebookTemplateId, string $templateName, int $wabaAccountId = null): array
    {
        $business = \App\Models\Business::find($businessId);
        if (!$business) {
            return [
                'success' => false,
                'error' => 'Business not found'
            ];
        }

        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            return [
                'success' => false,
                'error' => 'No active Facebook credentials found'
            ];
        }

        try {
            // Use specific WABA account if provided, otherwise find by template
            if ($wabaAccountId) {
                $wabaAccount = $business->wabaAccounts()->find($wabaAccountId);
            } else {
                // Find WABA account by looking up the template
                $template = \App\Models\WhatsAppTemplate::where('business_id', $businessId)
                    ->where('facebook_template_id', $facebookTemplateId)
                    ->first();

                $wabaAccount = $template ? $template->wabaAccount : $business->wabaAccounts()->first();
            }

            if (!$wabaAccount) {
                return [
                    'success' => false,
                    'error' => 'WABA account not found'
                ];
            }

            Log::info('Deleting template from Facebook', [
                'business_id' => $businessId,
                'waba_id' => $wabaAccount->waba_id,
                'facebook_template_id' => $facebookTemplateId,
                'template_name' => $templateName
            ]);

            // ✅ FIX: Delete using only template name (Facebook API requirement)
            // Facebook API: DELETE /{waba-id}/message_templates?name={template_name}
            $response = Http::withToken($credentials->access_token)
                ->delete("https://graph.facebook.com/v24.0/{$wabaAccount->waba_id}/message_templates?name=" . urlencode($templateName));

            if ($response->failed()) {
                $error = 'Failed to delete template: ' . $response->body();
                Log::error($error, [
                    'business_id' => $businessId,
                    'facebook_template_id' => $facebookTemplateId
                ]);

                return [
                    'success' => false,
                    'error' => $error,
                    'facebook_error' => $response->json()
                ];
            }

            Log::info('Template deleted successfully from Facebook', [
                'business_id' => $businessId,
                'facebook_template_id' => $facebookTemplateId
            ]);

            return [
                'success' => true,
                'message' => 'Template deleted from Facebook'
            ];
        } catch (\Exception $e) {
            Log::error('Template deletion failed', [
                'business_id' => $businessId,
                'facebook_template_id' => $facebookTemplateId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Send message (simple wrapper for existing sendTextMessage)
     */
    public function sendMessage(int $businessId, string $phoneNumber, string $message): array
    {
        $business = \App\Models\Business::find($businessId);
        if (!$business) {
            return [
                'success' => false,
                'error' => 'Business not found'
            ];
        }

        // Get first active phone number for this business
        $businessPhoneNumber = $business->phoneNumbers()->active()->first();
        if (!$businessPhoneNumber) {
            return [
                'success' => false,
                'error' => 'No active phone number found for business'
            ];
        }

        return $this->sendTextMessage(
            $business,
            $businessPhoneNumber->phone_number_id,
            $phoneNumber,
            $message
        );
    }
    /**
     * Request a verification code for a phone number.
     */
    public function requestVerificationCode(Business $business, string $phoneNumberId, string $method = 'SMS'): array
    {
        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            return ['success' => false, 'error' => 'No active Facebook credentials found'];
        }

        try {
            Log::info('Requesting verification code from Facebook', [
                'business_id' => $business->id,
                'phone_number_id' => $phoneNumberId,
                'method' => $method
            ]);

            $response = Http::withToken($credentials->access_token)
                ->post("https://graph.facebook.com/v24.0/{$phoneNumberId}/request_code", [
                    'code_method' => $method,
                    'language' => 'fr'
                ]);

            if ($response->failed()) {
                $errorBody = $response->json();
                $errorMessage = $errorBody['error']['message'] ?? 'Failed to request verification code.';
                Log::error('Facebook verification code request failed', [
                    'phone_number_id' => $phoneNumberId,
                    'error' => $response->body()
                ]);
                return ['success' => false, 'error' => $errorMessage];
            }

            return ['success' => true, 'data' => $response->json()];
        } catch (\Exception $e) {
            Log::error('Exception requesting verification code', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Verify a phone number with a code.
     */
    public function verifyCode(Business $business, string $phoneNumberId, string $code): array
    {
        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            return ['success' => false, 'error' => 'No active Facebook credentials found'];
        }

        try {
            Log::info('Verifying code with Facebook', [
                'business_id' => $business->id,
                'phone_number_id' => $phoneNumberId
            ]);

            $response = Http::withToken($credentials->access_token)
                ->post("https://graph.facebook.com/v24.0/{$phoneNumberId}/verify_code", [
                    'code' => $code
                ]);

            if ($response->failed()) {
                $errorBody = $response->json();
                $errorMessage = $errorBody['error']['message'] ?? 'Failed to verify code.';
                Log::error('Facebook code verification failed', [
                    'phone_number_id' => $phoneNumberId,
                    'error' => $response->body()
                ]);
                return ['success' => false, 'error' => $errorMessage];
            }

            return ['success' => true, 'data' => $response->json()];
        } catch (\Exception $e) {
            Log::error('Exception verifying code', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // Add this method to your FacebookMultiTenantService
    public function getPhoneNumberStatus(Business $business, string $phoneNumberId): array
    {
        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            return ['success' => false, 'error' => 'No active Facebook credentials found'];
        }

        try {
            $response = Http::withToken($credentials->access_token)
                ->get("https://graph.facebook.com/v24.0/{$phoneNumberId}");

            if ($response->failed()) {
                Log::error('Failed to get phone number status', [
                    'phone_number_id' => $phoneNumberId,
                    'error' => $response->body()
                ]);
                return ['success' => false, 'error' => 'Failed to get phone number status'];
            }

            $data = $response->json();
            return [
                'success' => true,
                'data' => [
                    'verified' => $data['verified'] ?? false,
                    'code_verification_status' => $data['code_verification_status'] ?? null,
                    'status' => $data['status'] ?? null
                ]
            ];
        } catch (\Exception $e) {
            Log::error('Exception getting phone number status', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * ========================================
     * CATALOGUE MANAGEMENT METHODS
     * Based on: https://developers.facebook.com/docs/marketing-api/catalog
     * ========================================
     */

    /**
     * Create a new product catalog
     * https://developers.facebook.com/docs/marketing-api/catalog/guides/catalog-creation
     */
    public function createCatalogue(Business $business, array $data): array
    {
        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            return ['success' => false, 'error' => 'No active Facebook credentials found'];
        }

        // Check if facebook_business_id exists
        if (!$credentials->meta_business_id) {
            Log::error('Facebook Business ID not found', [
                'business_id' => $business->id,
                'credentials_id' => $credentials->id
            ]);
            return ['success' => false, 'error' => 'Facebook Business ID not configured'];
        }

        try {
            Log::info('Creating Facebook catalogue', [
                'business_id' => $business->id,
                'facebook_business_id' => $credentials->meta_business_id,
                'name' => $data['name'] ?? null,
                'vertical' => $data['vertical'] ?? 'commerce'
            ]);

            $response = Http::withToken($credentials->access_token)
                ->post("https://graph.facebook.com/v24.0/{$credentials->meta_business_id}/owned_product_catalogs", [
                    'name' => $data['name'],
                    'vertical' => $data['vertical'] ?? 'commerce',
                ]);

            if ($response->failed()) {
                $errorResponse = $response->json();

                Log::error('Failed to create catalogue', [
                    'business_id' => $business->id,
                    'status' => $response->status(),
                    'response' => $errorResponse,
                    'error_message' => $errorResponse['error']['message'] ?? 'Unknown error',
                    'error_code' => $errorResponse['error']['code'] ?? null,
                    'error_subcode' => $errorResponse['error']['error_subcode'] ?? null
                ]);

                return [
                    'success' => false,
                    'error' => $errorResponse['error']['message'] ?? 'Failed to create catalogue',
                    'error_code' => $errorResponse['error']['code'] ?? null
                ];
            }

            $responseData = $response->json();

            // Verify the response contains the ID
            if (!isset($responseData['id'])) {
                Log::error('Catalogue created but ID not returned', [
                    'business_id' => $business->id,
                    'response' => $responseData
                ]);

                return [
                    'success' => false,
                    'error' => 'Catalogue ID not returned by Facebook'
                ];
            }

            Log::info('Catalogue created successfully', [
                'business_id' => $business->id,
                'catalogue_id' => $responseData['id'],
                'catalogue_name' => $responseData['name'] ?? $data['name']
            ]);

            return [
                'success' => true,
                'catalogue_id' => $responseData['id'],
                'data' => $responseData
            ];
        } catch (\Exception $e) {
            Log::error('Exception creating catalogue', [
                'business_id' => $business->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * List all product catalogs for a business
     */
    public function listCatalogues(Business $business): array
    {
        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            return ['success' => false, 'error' => 'No active Facebook credentials found'];
        }

        // Check if facebook_business_id exists
        if (!$credentials->meta_business_id) {
            Log::error('Facebook Business ID not found for listing catalogues', [
                'business_id' => $business->id,
                'credentials_id' => $credentials->id
            ]);
            return ['success' => false, 'error' => 'Facebook Business ID not configured'];
        }

        try {
            Log::info('Fetching catalogues from Facebook', [
                'business_id' => $business->id,
                'facebook_business_id' => $credentials->meta_business_id
            ]);

            $response = Http::withToken($credentials->access_token)
                ->get("https://graph.facebook.com/v24.0/{$credentials->meta_business_id}/owned_product_catalogs", [
                    'fields' => 'id,name,vertical,product_count,description'
                ]);

            if ($response->failed()) {
                $errorResponse = $response->json();

                Log::error('Failed to list catalogues', [
                    'business_id' => $business->id,
                    'status' => $response->status(),
                    'response' => $errorResponse,
                    'error_message' => $errorResponse['error']['message'] ?? 'Unknown error',
                    'error_code' => $errorResponse['error']['code'] ?? null
                ]);

                return [
                    'success' => false,
                    'error' => $errorResponse['error']['message'] ?? 'Failed to list catalogues'
                ];
            }

            $responseData = $response->json();
            $catalogues = $responseData['data'] ?? [];

            Log::info('Catalogues fetched successfully', [
                'business_id' => $business->id,
                'count' => count($catalogues)
            ]);

            return [
                'success' => true,
                'data' => $catalogues,
                'paging' => $responseData['paging'] ?? null
            ];
        } catch (\Exception $e) {
            Log::error('Exception listing catalogues', [
                'business_id' => $business->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Create a product in a catalogue
     * https://developers.facebook.com/docs/marketing-api/catalog/guides/batch-upload
     */
    public function createCatalogueProduct(Business $business, string $catalogueId, array $productData): array
    {
        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            return ['success' => false, 'error' => 'No active Facebook credentials found'];
        }

        try {
            Log::info('Creating catalogue product', [
                'business_id' => $business->id,
                'catalogue_id' => $catalogueId,
                'retailer_id' => $productData['retailer_id'] ?? null
            ]);

            // Prepare product data according to Facebook's format
            // Note: Facebook requires 'name' not 'title'
            $facebookProductData = [
                'retailer_id' => $productData['retailer_id'],
                'name' => $productData['name'], // REQUIRED: Facebook uses 'name', not 'title'
                'description' => $productData['description'] ?? '',
                'availability' => $productData['availability'] ?? 'in stock',
                'condition' => $productData['condition'] ?? 'new',
                'price' => $productData['price'] * 100, // Facebook uses cents
                'currency' => $productData['currency'] ?? 'XAF',
                'image_url' => $productData['image_url'] ?? '',
                'url' => $productData['url'] ?? '',
            ];

            // Optional fields
            if (isset($productData['brand'])) {
                $facebookProductData['brand'] = $productData['brand'];
            }
            if (isset($productData['category'])) {
                $facebookProductData['google_product_category'] = $productData['category'];
            }
            if (isset($productData['gtin'])) {
                $facebookProductData['gtin'] = $productData['gtin'];
            }
            if (isset($productData['mpn'])) {
                $facebookProductData['mpn'] = $productData['mpn'];
            }
            // ✅ FIX: Facebook expects additional_image_urls as a COMMA-SEPARATED STRING, not an array
            if (isset($productData['additional_images']) && is_array($productData['additional_images']) && count($productData['additional_images']) > 0) {
                $facebookProductData['additional_image_urls'] = implode(',', $productData['additional_images']);
            }
            if (isset($productData['inventory'])) {
                $facebookProductData['inventory'] = $productData['inventory'];
            }

            $response = Http::withToken($credentials->access_token)
                ->post("https://graph.facebook.com/v24.0/{$catalogueId}/products", $facebookProductData);

            if ($response->failed()) {
                $errorData = $response->json();
                Log::error('Failed to create product', [
                    'business_id' => $business->id,
                    'catalogue_id' => $catalogueId,
                    'status_code' => $response->status(),
                    'error' => $errorData['error'] ?? null,
                    'full_response' => $errorData
                ]);

                return [
                    'success' => false,
                    'error' => $errorData['error']['message'] ?? 'Failed to create product',
                    'error_code' => $errorData['error']['code'] ?? null,
                    'error_subcode' => $errorData['error']['error_subcode'] ?? null
                ];
            }

            $responseData = $response->json();

            // Facebook returns just an ID in the response
            // According to: https://developers.facebook.com/docs/marketing-api/reference/product-catalog#Creating
            if (!isset($responseData['id'])) {
                Log::error('Product created but no ID returned', [
                    'business_id' => $business->id,
                    'catalogue_id' => $catalogueId,
                    'response_data' => $responseData,
                    'response_keys' => array_keys($responseData)
                ]);

                return [
                    'success' => false,
                    'error' => 'Product ID not returned by Facebook',
                    'response' => $responseData
                ];
            }

            Log::info('Product created successfully', [
                'business_id' => $business->id,
                'catalogue_id' => $catalogueId,
                'product_id' => $responseData['id'],
                'retailer_id' => $productData['retailer_id']
            ]);

            return [
                'success' => true,
                'product_id' => $responseData['id'],
                'data' => $responseData
            ];
        } catch (\Exception $e) {
            Log::error('Exception creating product', [
                'business_id' => $business->id,
                'error' => $e->getMessage()
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Update a product in a catalogue
     */
    public function updateCatalogueProduct(Business $business, string $productId, array $productData): array
    {
        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            return ['success' => false, 'error' => 'No active Facebook credentials found'];
        }

        try {
            Log::info('Updating catalogue product', [
                'business_id' => $business->id,
                'product_id' => $productId
            ]);

            // Prepare update data
            $updateData = [];

            if (isset($productData['name'])) {
                $updateData['name'] = $productData['name']; // Facebook uses 'name', not 'title'
            }
            if (isset($productData['description'])) {
                $updateData['description'] = $productData['description'];
            }
            if (isset($productData['price'])) {
                $updateData['price'] = $productData['price'] * 100; // Facebook uses cents
            }
            if (isset($productData['currency'])) {
                $updateData['currency'] = $productData['currency'];
            }
            if (isset($productData['availability'])) {
                $updateData['availability'] = $productData['availability'];
            }
            if (isset($productData['condition'])) {
                $updateData['condition'] = $productData['condition'];
            }
            if (isset($productData['image_url'])) {
                $updateData['image_url'] = $productData['image_url'];
            }
            if (isset($productData['brand'])) {
                $updateData['brand'] = $productData['brand'];
            }
            if (isset($productData['inventory'])) {
                $updateData['inventory'] = $productData['inventory'];
            }
            // ✅ FIX: Facebook expects additional_image_urls as a COMMA-SEPARATED STRING, not an array
            if (isset($productData['additional_images']) && is_array($productData['additional_images']) && count($productData['additional_images']) > 0) {
                $updateData['additional_image_urls'] = implode(',', $productData['additional_images']);
            }

            $response = Http::withToken($credentials->access_token)
                ->post("https://graph.facebook.com/v24.0/{$productId}", $updateData);

            if ($response->failed()) {
                Log::error('Failed to update product', [
                    'business_id' => $business->id,
                    'product_id' => $productId,
                    'response' => $response->json()
                ]);

                return [
                    'success' => false,
                    'error' => $response->json()['error']['message'] ?? 'Failed to update product'
                ];
            }

            Log::info('Product updated successfully', [
                'business_id' => $business->id,
                'product_id' => $productId
            ]);

            return [
                'success' => true,
                'data' => $response->json()
            ];
        } catch (\Exception $e) {
            Log::error('Exception updating product', [
                'business_id' => $business->id,
                'error' => $e->getMessage()
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Delete a product from a catalogue
     */
    /**
     * Get product details including review status
     * 
     * @param Business $business
     * @param string $productId
     * @return array
     */
    public function getCatalogueProductStatus(Business $business, string $productId): array
    {
        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            return ['success' => false, 'error' => 'No active Facebook credentials found'];
        }

        try {
            Log::info('Getting catalogue product status', [
                'business_id' => $business->id,
                'product_id' => $productId
            ]);

            // ✅ Request product details with review_status, visibility, and other key fields
            $response = Http::withToken($credentials->access_token)
                ->get("https://graph.facebook.com/v24.0/{$productId}", [
                    'fields' => 'id,retailer_id,name,description,price,availability,visibility,review_status,review_rejection_reasons,image_url,url'
                ]);

            if ($response->failed()) {
                Log::error('Failed to get product status', [
                    'business_id' => $business->id,
                    'product_id' => $productId,
                    'response' => $response->json()
                ]);

                return [
                    'success' => false,
                    'error' => $response->json()['error']['message'] ?? 'Failed to get product status'
                ];
            }

            $productData = $response->json();

            Log::info('Product status retrieved successfully', [
                'business_id' => $business->id,
                'product_id' => $productId,
                'review_status' => $productData['review_status'] ?? 'unknown',
                'availability' => $productData['availability'] ?? 'unknown',
                'visibility' => $productData['visibility'] ?? 'unknown'
            ]);

            return [
                'success' => true,
                'data' => $productData
            ];
        } catch (\Exception $e) {
            Log::error('Exception getting product status', [
                'business_id' => $business->id,
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function deleteCatalogueProduct(Business $business, string $productId): array
    {
        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            return ['success' => false, 'error' => 'No active Facebook credentials found'];
        }

        try {
            Log::info('Deleting catalogue product', [
                'business_id' => $business->id,
                'product_id' => $productId
            ]);

            $response = Http::withToken($credentials->access_token)
                ->delete("https://graph.facebook.com/v24.0/{$productId}");

            if ($response->failed()) {
                Log::error('Failed to delete product', [
                    'business_id' => $business->id,
                    'product_id' => $productId,
                    'response' => $response->json()
                ]);

                return [
                    'success' => false,
                    'error' => $response->json()['error']['message'] ?? 'Failed to delete product'
                ];
            }

            Log::info('Product deleted successfully', [
                'business_id' => $business->id,
                'product_id' => $productId
            ]);

            return [
                'success' => true,
                'data' => $response->json()
            ];
        } catch (\Exception $e) {
            Log::error('Exception deleting product', [
                'business_id' => $business->id,
                'error' => $e->getMessage()
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * List products in a catalogue
     */
    public function listCatalogueProducts(Business $business, string $catalogueId, array $params = []): array
    {
        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            return ['success' => false, 'error' => 'No active Facebook credentials found'];
        }

        try {
            $queryParams = [
                'fields' => 'id,retailer_id,name,description,price,currency,availability,image_url,url',
                'limit' => $params['limit'] ?? 100
            ];

            if (isset($params['after'])) {
                $queryParams['after'] = $params['after'];
            }

            $response = Http::withToken($credentials->access_token)
                ->get("https://graph.facebook.com/v24.0/{$catalogueId}/products", $queryParams);

            if ($response->failed()) {
                return [
                    'success' => false,
                    'error' => $response->json()['error']['message'] ?? 'Failed to list products'
                ];
            }

            $responseData = $response->json();

            return [
                'success' => true,
                'data' => $responseData['data'] ?? [],
                'paging' => $responseData['paging'] ?? null
            ];
        } catch (\Exception $e) {
            Log::error('Exception listing products', [
                'business_id' => $business->id,
                'error' => $e->getMessage()
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Connect a catalogue to a WhatsApp phone number
     * https://developers.facebook.com/docs/whatsapp/business-management-api/catalog-management
     */
    public function connectCatalogueToWhatsApp(Business $business, string $phoneNumberId, string $catalogueId): array
    {
        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            return ['success' => false, 'error' => 'No active Facebook credentials found'];
        }

        try {
            Log::info('Connecting catalogue to WhatsApp', [
                'business_id' => $business->id,
                'phone_number_id' => $phoneNumberId,
                'catalogue_id' => $catalogueId
            ]);

            $response = Http::withToken($credentials->access_token)
                ->post("https://graph.facebook.com/v24.0/{$phoneNumberId}/whatsapp_commerce_settings", [
                    'is_catalog_visible' => true,
                    'catalog_id' => $catalogueId
                ]);

            if ($response->failed()) {
                Log::error('Failed to connect catalogue to WhatsApp', [
                    'business_id' => $business->id,
                    'phone_number_id' => $phoneNumberId,
                    'catalogue_id' => $catalogueId,
                    'response' => $response->json()
                ]);

                return [
                    'success' => false,
                    'error' => $response->json()['error']['message'] ?? 'Failed to connect catalogue'
                ];
            }

            Log::info('Catalogue connected to WhatsApp successfully', [
                'business_id' => $business->id,
                'phone_number_id' => $phoneNumberId,
                'catalogue_id' => $catalogueId
            ]);

            return [
                'success' => true,
                'data' => $response->json()
            ];
        } catch (\Exception $e) {
            Log::error('Exception connecting catalogue to WhatsApp', [
                'business_id' => $business->id,
                'error' => $e->getMessage()
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Disconnect catalogue from WhatsApp phone number
     */
    public function disconnectCatalogueFromWhatsApp(Business $business, string $phoneNumberId): array
    {
        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            return ['success' => false, 'error' => 'No active Facebook credentials found'];
        }

        try {
            Log::info('Disconnecting catalogue from WhatsApp', [
                'business_id' => $business->id,
                'phone_number_id' => $phoneNumberId
            ]);

            $response = Http::withToken($credentials->access_token)
                ->post("https://graph.facebook.com/v24.0/{$phoneNumberId}/whatsapp_commerce_settings", [
                    'is_catalog_visible' => false
                ]);

            if ($response->failed()) {
                return [
                    'success' => false,
                    'error' => $response->json()['error']['message'] ?? 'Failed to disconnect catalogue'
                ];
            }

            return [
                'success' => true,
                'data' => $response->json()
            ];
        } catch (\Exception $e) {
            Log::error('Exception disconnecting catalogue from WhatsApp', [
                'business_id' => $business->id,
                'error' => $e->getMessage()
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get catalogue commerce settings for a WhatsApp phone number
     */
    public function getWhatsAppCommerceSettings(Business $business, string $phoneNumberId): array
    {
        $credentials = $this->getCredentials($business);
        if (!$credentials) {
            return ['success' => false, 'error' => 'No active Facebook credentials found'];
        }

        try {
            $response = Http::withToken($credentials->access_token)
                ->get("https://graph.facebook.com/v24.0/{$phoneNumberId}/whatsapp_commerce_settings", [
                    'fields' => 'is_catalog_visible,catalog_id'
                ]);

            if ($response->failed()) {
                return [
                    'success' => false,
                    'error' => $response->json()['error']['message'] ?? 'Failed to get commerce settings'
                ];
            }

            return [
                'success' => true,
                'data' => $response->json()['data'] ?? []
            ];
        } catch (\Exception $e) {
            Log::error('Exception getting commerce settings', [
                'business_id' => $business->id,
                'error' => $e->getMessage()
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
