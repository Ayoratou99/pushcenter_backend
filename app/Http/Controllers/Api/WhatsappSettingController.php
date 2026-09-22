<?php

namespace App\Http\Controllers\Api;

use App\Models\Business;
use App\Models\WhatsappSetting;
use App\Services\AyosPush\AyosPushClient;
use App\Services\AyosPush\AyosPushException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * WhatsApp channel of an application: the AyosPush API key it sends with.
 *
 * The Meta side (Facebook connection, WhatsApp Business Accounts, phone
 * numbers) is managed in AyosPush; the connection test takes a snapshot of it.
 */
class WhatsappSettingController extends BaseController
{
    /**
     * @OA\Get(
     *     path="/api/v1/businesses/{businessId}/whatsapp-settings",
     *     tags={"WhatsApp (AyosPush)"},
     *     security={{"bearerAuth":{}}},
     *     summary="AyosPush configuration of an application",
     *     description="The API secret is never returned, only its last four characters. `data` is absent when WhatsApp is not configured.",
     *     @OA\Parameter(name="businessId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Configuration, or no data when not configured"),
     *     @OA\Response(response=403, description="Application outside the user's scope"),
     *     @OA\Response(response=404, description="Application not found")
     * )
     */
    public function show(int $businessId): JsonResponse
    {
        if ($deny = $this->denyUnlessBusinessAccessible($businessId)) {
            return $deny;
        }

        if (! Business::whereKey($businessId)->exists()) {
            return $this->notFoundResponse('Application');
        }

        $setting = WhatsappSetting::where('business_id', $businessId)->first();

        return $setting
            ? $this->successResponse($setting->toConsoleArray())
            : $this->successResponse(null, 'WhatsApp is not configured for this application');
    }

    /**
     * @OA\Put(
     *     path="/api/v1/businesses/{businessId}/whatsapp-settings",
     *     tags={"WhatsApp (AyosPush)"},
     *     security={{"bearerAuth":{}}},
     *     summary="Save the AyosPush API key of an application",
     *     description="Creates or updates the configuration. Leave api_secret empty to keep the stored one. Changing the key resets the connection test.",
     *     @OA\Parameter(name="businessId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"api_key"},
     *         @OA\Property(property="api_key", type="string"),
     *         @OA\Property(property="api_secret", type="string", description="Required the first time"),
     *         @OA\Property(property="default_waba_account_id", type="integer", nullable=true, description="AyosPush WABA id templates are created on"),
     *         @OA\Property(property="default_phone_number_id", type="string", nullable=true, description="Meta phone number id used as sender")
     *     )),
     *     @OA\Response(response=200, description="Saved"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function update(Request $request, int $businessId): JsonResponse
    {
        if ($deny = $this->denyUnlessBusinessAccessible($businessId)) {
            return $deny;
        }

        if (! Business::whereKey($businessId)->exists()) {
            return $this->notFoundResponse('Application');
        }

        $setting = WhatsappSetting::where('business_id', $businessId)->first();
        $knownWabas = array_column($setting?->waba_accounts ?? [], 'id');
        $knownPhones = array_column($setting?->phone_numbers ?? [], 'phone_number_id');

        $validator = Validator::make($request->all(), [
            'api_key' => 'required|string|max:255',
            'api_secret' => [$setting ? 'nullable' : 'required', 'string', 'max:1024'],
            'default_waba_account_id' => array_filter([
                'nullable', 'integer', 'min:1',
                $knownWabas ? Rule::in($knownWabas) : null,
            ]),
            'default_phone_number_id' => array_filter([
                'nullable', 'string', 'max:64',
                $knownPhones ? Rule::in($knownPhones) : null,
            ]),
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $setting ??= new WhatsappSetting([
            'business_id' => $businessId,
            'provider' => WhatsappSetting::PROVIDER_AYOSPUSH,
        ]);

        $apiKey = trim((string) $request->input('api_key'));
        $secret = trim((string) $request->input('api_secret', ''));
        $keyChanged = $setting->api_key !== $apiKey;
        $credentialsChanged = $keyChanged || ($secret !== '' && $secret !== $setting->api_secret);

        $setting->api_key = $apiKey;
        if ($secret !== '') {
            $setting->api_secret = $secret;
        }

        if ($credentialsChanged) {
            $setting->forceFill([
                'test_status' => 'not_tested',
                'test_error' => null,
                'scopes' => null,
            ]);
        }

        if ($keyChanged && $setting->exists) {
            // Another key may reach other accounts: forget what the old one saw.
            $setting->forceFill([
                'waba_accounts' => null,
                'phone_numbers' => null,
                'meta_business_id' => null,
                'connection_status' => null,
                'default_waba_account_id' => null,
                'default_phone_number_id' => null,
            ]);
        }

        if ($request->exists('default_waba_account_id')) {
            $setting->default_waba_account_id = $request->input('default_waba_account_id');
        }

        if ($request->exists('default_phone_number_id')) {
            $setting->default_phone_number_id = $request->input('default_phone_number_id');
        }

        $setting->save();

        return $this->successResponse($setting->toConsoleArray(), 'WhatsApp settings saved');
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/businesses/{businessId}/whatsapp-settings",
     *     tags={"WhatsApp (AyosPush)"},
     *     security={{"bearerAuth":{}}},
     *     summary="Remove the AyosPush configuration of an application",
     *     description="Templates keep their AyosPush identifiers and can be synchronised again once a key is saved.",
     *     @OA\Parameter(name="businessId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Removed"),
     *     @OA\Response(response=404, description="Not configured")
     * )
     */
    public function destroy(int $businessId): JsonResponse
    {
        if ($deny = $this->denyUnlessBusinessAccessible($businessId)) {
            return $deny;
        }

        $setting = WhatsappSetting::where('business_id', $businessId)->first();

        if (! $setting) {
            return $this->notFoundResponse('WhatsApp settings');
        }

        AyosPushClient::for($setting)->forgetToken();
        $setting->delete();

        return $this->deletedResponse('WhatsApp settings removed');
    }

    /**
     * @OA\Post(
     *     path="/api/v1/businesses/{businessId}/whatsapp-settings/test",
     *     tags={"WhatsApp (AyosPush)"},
     *     security={{"bearerAuth":{}}},
     *     summary="Test the AyosPush connection",
     *     description="Logs in with the stored key (POST /v1/auth/login), then reads GET /v1/facebook-config to list the WhatsApp Business Accounts and phone numbers the key can use. The result is stored on the configuration.",
     *     @OA\Parameter(name="businessId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Connected; the configuration now lists accounts and phone numbers"),
     *     @OA\Response(response=404, description="Not configured"),
     *     @OA\Response(response=422, description="AyosPush refused the key or the request (message explains why)"),
     *     @OA\Response(response=502, description="AyosPush could not be reached")
     * )
     */
    public function test(int $businessId): JsonResponse
    {
        if ($deny = $this->denyUnlessBusinessAccessible($businessId)) {
            return $deny;
        }

        $setting = WhatsappSetting::where('business_id', $businessId)->first();

        if (! $setting) {
            return $this->notFoundResponse('WhatsApp settings');
        }

        $client = AyosPushClient::for($setting);

        try {
            $client->authenticate(true);
            $setting->scopes = $client->scopes();
            $config = $client->facebookConfig();
        } catch (AyosPushException $e) {
            $setting->forceFill([
                'test_status' => 'failed',
                'test_error' => $e->getMessage(),
                'last_tested_at' => now(),
            ])->save();

            return $this->errorResponse(
                $e->getMessage(),
                $e->context() + ['settings' => $setting->toConsoleArray()],
                $e->consoleStatus()
            );
        }

        $wabas = collect($config['waba_accounts'] ?? [])
            ->filter(fn ($waba) => is_array($waba) && ! empty($waba['id']))
            ->map(fn (array $waba) => [
                'id' => (int) $waba['id'],
                'waba_id' => (string) ($waba['waba_id'] ?? ''),
                'name' => $waba['name'] ?? null,
                'status' => $waba['status'] ?? null,
                'verification_status' => $waba['verification_status'] ?? null,
                'phone_numbers_count' => (int) ($waba['phone_numbers_count'] ?? 0),
                'templates_count' => (int) ($waba['templates_count'] ?? 0),
            ])
            ->values()
            ->all();

        $phones = collect($config['phone_numbers'] ?? [])
            ->filter(fn ($phone) => is_array($phone) && ! empty($phone['phone_number_id']))
            ->map(fn (array $phone) => [
                'phone_number_id' => (string) $phone['phone_number_id'],
                'display_phone_number' => $phone['display_phone_number'] ?? null,
                'verified_name' => $phone['verified_name'] ?? null,
                'quality_rating' => $phone['quality_rating'] ?? null,
                'messaging_limit_tier' => $phone['messaging_limit_tier'] ?? null,
                'status' => $phone['status'] ?? null,
                'waba_account_id' => isset($phone['waba_account_id']) ? (int) $phone['waba_account_id'] : null,
            ])
            ->values()
            ->all();

        $wabaIds = array_column($wabas, 'id');
        $phoneIds = array_column($phones, 'phone_number_id');

        $setting->forceFill([
            'waba_accounts' => $wabas,
            'phone_numbers' => $phones,
            'meta_business_id' => $config['meta_business_id'] ?? null,
            'connection_status' => $config['connection_status'] ?? null,
            // Keep the choice while it is still reachable; pick the only
            // option when there is exactly one.
            'default_waba_account_id' => in_array($setting->default_waba_account_id, $wabaIds, true)
                ? $setting->default_waba_account_id
                : (count($wabaIds) === 1 ? $wabaIds[0] : null),
            'default_phone_number_id' => in_array($setting->default_phone_number_id, $phoneIds, true)
                ? $setting->default_phone_number_id
                : (count($phoneIds) === 1 ? $phoneIds[0] : null),
            'test_status' => 'success',
            'test_error' => null,
            'last_tested_at' => now(),
        ])->save();

        $missing = $setting->missingScopes();

        return $this->successResponse(
            $setting->toConsoleArray(),
            $missing
                ? 'Connected to AyosPush, but the API key is missing: ' . implode(', ', $missing)
                : 'Connected to AyosPush'
        );
    }
}
