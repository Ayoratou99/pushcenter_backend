<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseController;
use App\Jobs\SendSmtpTestEmail;
use App\Repositories\Contracts\SmtpRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class SmtpSettingController extends BaseController
{
    protected $smtpRepository;

    public function __construct(SmtpRepositoryInterface $smtpRepository)
    {
        $this->smtpRepository = $smtpRepository;
    }

    /**
     * @OA\Get(
     *     path="/api/v1/businesses/{businessId}/smtp-settings",
     *     tags={"SMTP Settings"},
     *     security={{"bearerAuth":{}}},
     *     summary="Get SMTP settings for a business",
     *     description="Returns all SMTP settings for a specific business",
     *     @OA\Parameter(
     *         name="businessId",
     *         in="path",
     *         description="Business ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/SmtpSetting"))
     *         )
     *     )
     * )
     */
    public function index(int $businessId): JsonResponse
    {
        $settings = DB::table('smtp_settings')
            ->where('business_id', $businessId)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->successResponse($settings);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/businesses/{businessId}/smtp-settings/{id}",
     *     tags={"SMTP Settings"},
     *     security={{"bearerAuth":{}}},
     *     summary="Get a specific SMTP setting",
     *     description="Returns a specific SMTP setting by ID",
     *     @OA\Parameter(
     *         name="businessId",
     *         in="path",
     *         description="Business ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="SMTP Setting ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/SmtpSetting")
     *         )
     *     ),
     *     @OA\Response(response=404, description="SMTP setting not found")
     * )
     */
    public function show(int $businessId, int $id): JsonResponse
    {
        $setting = $this->smtpRepository->find($id);

        if (!$setting || $setting->business_id !== $businessId) {
            return $this->notFoundResponse('SMTP setting');
        }

        return $this->successResponse($setting);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/smtp-settings/base",
     *     tags={"SMTP Settings"},
     *     security={{"bearerAuth":{}}},
     *     summary="Get available SMTP base configurations",
     *     description="Returns unique SMTP base configurations (host, port, encryption, username) that can be reused",
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object",
     *                 @OA\Property(property="id", type="integer"),
     *                 @OA\Property(property="host", type="string"),
     *                 @OA\Property(property="port", type="integer"),
     *                 @OA\Property(property="encryption", type="string"),
     *                 @OA\Property(property="username", type="string"),
     *                 @OA\Property(property="name", type="string")
     *             ))
     *         )
     *     )
     * )
     */
    public function getBaseConfigurations(): JsonResponse
    {
        // Get all SMTP settings from all businesses
        // This allows users to reuse any existing SMTP configuration
        $baseConfigs = DB::table('smtp_settings')
            ->join('businesses', 'smtp_settings.business_id', '=', 'businesses.id')
            ->select(
                'smtp_settings.id',
                'smtp_settings.host',
                'smtp_settings.port',
                'smtp_settings.encryption',
                'smtp_settings.username',
                DB::raw("smtp_settings.name || ' - ' || businesses.name as name")
            )
            ->whereNull('smtp_settings.deleted_at')
            ->whereNull('businesses.deleted_at')
            ->orderBy('smtp_settings.created_at', 'desc')
            ->get();

        // Convert to array and ensure id is integer
        $baseConfigs = $baseConfigs->map(function ($config) {
            return [
                'id' => (int) $config->id,
                'host' => $config->host,
                'port' => (int) $config->port,
                'encryption' => $config->encryption,
                'username' => $config->username,
                'name' => $config->name,
            ];
        });

        return $this->successResponse($baseConfigs);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/businesses/{businessId}/smtp-settings",
     *     tags={"SMTP Settings"},
     *     security={{"bearerAuth":{}}},
     *     summary="Create a new SMTP setting",
     *     description="Creates a new SMTP setting for a business. Can reuse existing base configuration by providing base_smtp_id.",
     *     @OA\Parameter(
     *         name="businessId",
     *         in="path",
     *         description="Business ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "from_email", "from_name"},
     *             @OA\Property(property="name", type="string", example="Primary SMTP"),
     *             @OA\Property(property="description", type="string", example="Main email configuration"),
     *             @OA\Property(property="base_smtp_id", type="integer", example=1, description="ID of existing SMTP to reuse base config"),
     *             @OA\Property(property="host", type="string", example="smtp.gmail.com"),
     *             @OA\Property(property="port", type="integer", example=587),
     *             @OA\Property(property="encryption", type="string", enum={"tls", "ssl", "none"}, example="tls"),
     *             @OA\Property(property="username", type="string", example="user@example.com"),
     *             @OA\Property(property="password", type="string", example="password123"),
     *             @OA\Property(property="from_email", type="string", example="sender@example.com"),
     *             @OA\Property(property="from_name", type="string", example="My Company"),
     *             @OA\Property(property="reply_to_email", type="string", example="reply@example.com"),
     *             @OA\Property(property="reply_to_name", type="string", example="Support"),
     *             @OA\Property(property="timeout", type="integer", example=30),
     *             @OA\Property(property="verify_peer", type="boolean", example=true),
     *             @OA\Property(property="is_active", type="boolean", example=true),
     *             @OA\Property(property="is_default", type="boolean", example=false),
     *             @OA\Property(property="second_limit", type="integer", example=10),
     *             @OA\Property(property="hourly_limit", type="integer", example=1000),
     *             @OA\Property(property="daily_limit", type="integer", example=10000)
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="SMTP setting created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="SMTP setting created successfully"),
     *             @OA\Property(property="data", ref="#/components/schemas/SmtpSetting")
     *         )
     *     ),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(Request $request, int $businessId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'base_smtp_id' => 'nullable|integer|exists:smtp_settings,id',
            'host' => 'required_without:base_smtp_id|string|max:255',
            'port' => 'required_without:base_smtp_id|integer|min:1|max:65535',
            'encryption' => 'required_without:base_smtp_id|in:tls,ssl,none',
            'username' => 'required_without:base_smtp_id|string|max:255',
            'password' => 'required_without:base_smtp_id|string',
            'from_email' => 'required|email|max:255',
            'from_name' => 'required|string|max:255',
            'reply_to_email' => 'nullable|email|max:255',
            'reply_to_name' => 'nullable|string|max:255',
            'timeout' => 'nullable|integer|min:1|max:300',
            'verify_peer' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'is_default' => 'nullable|boolean',
            'second_limit' => 'nullable|integer|min:0',
            'hourly_limit' => 'nullable|integer|min:0',
            'daily_limit' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $data = $request->all();
        $data['business_id'] = $businessId;

        // If base_smtp_id is provided, reuse base configuration
        if (!empty($data['base_smtp_id'])) {
            $baseSmtp = $this->smtpRepository->find($data['base_smtp_id']);
            if (!$baseSmtp) {
                return $this->errorResponse('Base SMTP configuration not found', null, 404);
            }

            // Copy base configuration
            $data['host'] = $baseSmtp->host;
            $data['port'] = $baseSmtp->port;
            $data['encryption'] = $baseSmtp->encryption;
            $data['username'] = $baseSmtp->username;
            $data['password'] = $baseSmtp->password; // Will be encrypted by model
            unset($data['base_smtp_id']);
        }

        // Set defaults
        $data['timeout'] = $data['timeout'] ?? 30;
        $data['verify_peer'] = $data['verify_peer'] ?? true;
        $data['is_active'] = $data['is_active'] ?? true;
        $data['is_default'] = $data['is_default'] ?? false;

        // If this is set as default, unset others
        if ($data['is_default']) {
            DB::table('smtp_settings')
                ->where('business_id', $businessId)
                ->update(['is_default' => false]);
        }

        $setting = $this->smtpRepository->create($data);

        return $this->successResponse($setting, 'SMTP setting created successfully', 201);
    }

    /**
     * @OA\Put(
     *     path="/api/v1/businesses/{businessId}/smtp-settings/{id}",
     *     tags={"SMTP Settings"},
     *     security={{"bearerAuth":{}}},
     *     summary="Update an SMTP setting",
     *     description="Updates an existing SMTP setting",
     *     @OA\Parameter(
     *         name="businessId",
     *         in="path",
     *         description="Business ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="SMTP Setting ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", example="Primary SMTP"),
     *             @OA\Property(property="description", type="string"),
     *             @OA\Property(property="host", type="string"),
     *             @OA\Property(property="port", type="integer"),
     *             @OA\Property(property="encryption", type="string", enum={"tls", "ssl", "none"}),
     *             @OA\Property(property="username", type="string"),
     *             @OA\Property(property="password", type="string", description="Leave empty to keep current"),
     *             @OA\Property(property="from_email", type="string"),
     *             @OA\Property(property="from_name", type="string"),
     *             @OA\Property(property="second_limit", type="integer"),
     *             @OA\Property(property="hourly_limit", type="integer"),
     *             @OA\Property(property="daily_limit", type="integer")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="SMTP setting updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="SMTP setting updated successfully"),
     *             @OA\Property(property="data", ref="#/components/schemas/SmtpSetting")
     *         )
     *     ),
     *     @OA\Response(response=404, description="SMTP setting not found")
     * )
     */
    public function update(Request $request, int $businessId, int $id): JsonResponse
    {
        $setting = $this->smtpRepository->find($id);

        if (!$setting || $setting->business_id !== $businessId) {
            return $this->notFoundResponse('SMTP setting');
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'host' => 'sometimes|required|string|max:255',
            'port' => 'sometimes|required|integer|min:1|max:65535',
            'encryption' => 'sometimes|required|in:tls,ssl,none',
            'username' => 'sometimes|required|string|max:255',
            'password' => 'nullable|string',
            'from_email' => 'sometimes|required|email|max:255',
            'from_name' => 'sometimes|required|string|max:255',
            'reply_to_email' => 'nullable|email|max:255',
            'reply_to_name' => 'nullable|string|max:255',
            'timeout' => 'nullable|integer|min:1|max:300',
            'verify_peer' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'is_default' => 'nullable|boolean',
            'second_limit' => 'nullable|integer|min:0',
            'hourly_limit' => 'nullable|integer|min:0',
            'daily_limit' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $data = $request->all();

        // Don't update password if not provided
        if (empty($data['password'])) {
            unset($data['password']);
        }

        // If setting as default, unset others
        if (isset($data['is_default']) && $data['is_default']) {
            DB::table('smtp_settings')
                ->where('business_id', $businessId)
                ->where('id', '!=', $id)
                ->update(['is_default' => false]);
        }

        $updated = $this->smtpRepository->update($id, $data);

        return $this->successResponse($updated, 'SMTP setting updated successfully');
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/businesses/{businessId}/smtp-settings/{id}",
     *     tags={"SMTP Settings"},
     *     security={{"bearerAuth":{}}},
     *     summary="Delete an SMTP setting",
     *     description="Soft deletes an SMTP setting",
     *     @OA\Parameter(
     *         name="businessId",
     *         in="path",
     *         description="Business ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="SMTP Setting ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="SMTP setting deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="SMTP setting deleted successfully")
     *         )
     *     ),
     *     @OA\Response(response=404, description="SMTP setting not found")
     * )
     */
    public function destroy(int $businessId, int $id): JsonResponse
    {
        $setting = $this->smtpRepository->find($id);

        if (!$setting || $setting->business_id !== $businessId) {
            return $this->notFoundResponse('SMTP setting');
        }

        $setting->delete();

        return $this->successResponse(null, 'SMTP setting deleted successfully');
    }

    /**
     * @OA\Post(
     *     path="/api/v1/businesses/{businessId}/smtp-settings/{id}/test",
     *     tags={"SMTP Settings"},
     *     security={{"bearerAuth":{}}},
     *     summary="Test SMTP configuration",
     *     description="Sends a test email using the SMTP configuration",
     *     @OA\Parameter(
     *         name="businessId",
     *         in="path",
     *         description="Business ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="SMTP Setting ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"recipient_email"},
     *             @OA\Property(property="recipient_email", type="string", format="email", example="test@example.com", description="Email address to send test email to")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Test email sent successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Test email sent successfully")
     *         )
     *     ),
     *     @OA\Response(response=404, description="SMTP setting not found"),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=500, description="Failed to send test email")
     * )
     */
    public function test(Request $request, int $businessId, int $id): JsonResponse
    {
        $setting = $this->smtpRepository->find($id);

        if (!$setting || $setting->business_id !== $businessId) {
            return $this->notFoundResponse('SMTP setting');
        }

        $validator = Validator::make($request->all(), [
            'recipient_email' => 'required|email|max:255',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        try {
            // Update status to pending (will be updated by job when sent)
            $setting->update([
                'test_status' => 'not_tested',
                'test_error' => null,
            ]);

            // Dispatch job to send test email (will be queued to 'emails' queue)
            SendSmtpTestEmail::dispatch($setting, $request->recipient_email);

            return $this->successResponse(
                null,
                'Test email queued successfully. It will be sent to ' . $request->recipient_email . ' shortly.'
            );
        } catch (\Exception $e) {
            // Update test status with error
            $setting->update([
                'test_status' => 'failed',
                'last_tested_at' => now(),
                'test_error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to queue test email: ' . $e->getMessage(),
                null,
                500
            );
        }
    }
}

