<?php

namespace App\Http\Controllers\Api;

use App\Repositories\Contracts\BusinessRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

/**
 * @OA\Tag(
 *     name="Businesses",
 *     description="API Endpoints for managing businesses"
 * )
 */
class BusinessController extends BaseController
{
    protected $businessRepository;

    public function __construct(BusinessRepositoryInterface $businessRepository)
    {
        $this->businessRepository = $businessRepository;
    }

    /**
     * @OA\Get(
     *     path="/api/businesses",
     *     tags={"Businesses"},
     *     summary="Get list of businesses",
     *     description="Returns a paginated list of businesses with optional search and status filter",
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         description="Number of items per page",
     *         required=false,
     *         @OA\Schema(type="integer", default=15)
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Search term for business name or email",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="Filter by business status",
     *         required=false,
     *         @OA\Schema(type="string", enum={"active", "inactive", "suspended"})
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Operation successful"),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Business"))
     *         )
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->get('per_page', 15);

        // Search
        if ($request->has('search')) {
            $businesses = $this->businessRepository->search($request->search, $perPage);
            return $this->successResponse($businesses);
        }

        // Filter by status
        if ($request->has('status')) {
            $businesses = $this->businessRepository->getByStatus($request->status, $perPage);
            return $this->successResponse($businesses);
        }

        // Get all
        $businesses = $this->businessRepository->all($perPage);
        return $this->successResponse($businesses);
    }

    /**
     * @OA\Post(
     *     path="/api/businesses",
     *     tags={"Businesses"},
     *     summary="Create a new business",
     *     description="Store a newly created business",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "email"},
     *             @OA\Property(property="name", type="string", example="Acme Corporation"),
     *             @OA\Property(property="email", type="string", format="email", example="contact@acme.com"),
     *             @OA\Property(property="phone_number", type="string", example="+237670000000"),
     *             @OA\Property(property="website", type="string", format="url", example="https://acme.com"),
     *             @OA\Property(property="description", type="string", example="Leading tech company"),
     *             @OA\Property(property="status", type="string", enum={"active", "inactive", "suspended"}, example="active"),
     *             @OA\Property(property="timezone", type="string", example="Africa/Douala")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Business created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Business created successfully"),
     *             @OA\Property(property="data", ref="#/components/schemas/Business")
     *         )
     *     ),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:businesses,email',
            'phone_number' => 'nullable|string',
            'website' => 'nullable|url',
            'description' => 'nullable|string',
            'status' => 'nullable|in:active,inactive,suspended',
            'timezone' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $business = $this->businessRepository->create($request->all());

        return $this->createdResponse($business, 'Business created successfully');
    }

    /**
     * @OA\Get(
     *     path="/api/businesses/{id}",
     *     tags={"Businesses"},
     *     summary="Get business by ID",
     *     description="Returns a single business",
     *     @OA\Parameter(
     *         name="id",
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
     *             @OA\Property(property="message", type="string", example="Operation successful"),
     *             @OA\Property(property="data", ref="#/components/schemas/Business")
     *         )
     *     ),
     *     @OA\Response(response=404, description="Business not found")
     * )
     */
    public function show($id): JsonResponse
    {
        $business = $this->businessRepository->find($id);

        if (!$business) {
            return $this->notFoundResponse('Business');
        }

        return $this->successResponse($business);
    }

    /**
     * @OA\Put(
     *     path="/api/businesses/{id}",
     *     tags={"Businesses"},
     *     summary="Update business",
     *     description="Update an existing business",
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
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="email", type="string", format="email"),
     *             @OA\Property(property="phone_number", type="string"),
     *             @OA\Property(property="website", type="string"),
     *             @OA\Property(property="description", type="string"),
     *             @OA\Property(property="status", type="string", enum={"active", "inactive", "suspended"}),
     *             @OA\Property(property="timezone", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Business updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Business updated successfully"),
     *             @OA\Property(property="data", ref="#/components/schemas/Business")
     *         )
     *     ),
     *     @OA\Response(response=404, description="Business not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function update(Request $request, $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:businesses,email,' . $id,
            'phone_number' => 'nullable|string',
            'website' => 'nullable|url',
            'description' => 'nullable|string',
            'status' => 'nullable|in:active,inactive,suspended',
            'timezone' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $business = $this->businessRepository->update($id, $request->all());

        if (!$business) {
            return $this->notFoundResponse('Business');
        }

        return $this->updatedResponse($business, 'Business updated successfully');
    }

    /**
     * @OA\Delete(
     *     path="/api/businesses/{id}",
     *     tags={"Businesses"},
     *     summary="Delete business",
     *     description="Delete an existing business (soft delete)",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Business ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Business deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Business deleted successfully")
     *         )
     *     ),
     *     @OA\Response(response=404, description="Business not found")
     * )
     */
    public function destroy($id): JsonResponse
    {
        $deleted = $this->businessRepository->delete($id);

        if (!$deleted) {
            return $this->notFoundResponse('Business');
        }

        return $this->deletedResponse('Business deleted successfully');
    }

    /**
     * @OA\Get(
     *     path="/api/businesses/{id}/stats",
     *     tags={"Businesses"},
     *     summary="Get business statistics",
     *     description="Returns statistics for a specific business",
     *     @OA\Parameter(
     *         name="id",
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
     *             @OA\Property(property="message", type="string", example="Operation successful"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="total_messages", type="integer"),
     *                 @OA\Property(property="total_templates", type="integer"),
     *                 @OA\Property(property="messages_by_type", type="object"),
     *                 @OA\Property(property="messages_by_status", type="object")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=404, description="Business not found")
     * )
     */
    public function stats($id): JsonResponse
    {
        $stats = $this->businessRepository->getStats($id);

        if (!$stats) {
            return $this->notFoundResponse('Business');
        }

        return $this->successResponse($stats);
    }

    /**
     * @OA\Post(
     *     path="/api/businesses/{id}/regenerate-credentials",
     *     tags={"Businesses"},
     *     summary="Regenerate app credentials",
     *     description="Generate new app_id and app_secret for a business",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Business ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Credentials regenerated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="App credentials regenerated successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="app_id", type="string", example="app_1234567890"),
     *                 @OA\Property(property="app_secret", type="string", example="secret_abcdefghijklmnopqrstuvwxyz")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Business not found"
     *     )
     * )
     */
    public function regenerateCredentials($id): JsonResponse
    {
        $business = $this->businessRepository->find($id);

        if (!$business) {
            return $this->notFoundResponse('Business');
        }

        // Generate new credentials
        $appId = 'app_' . bin2hex(random_bytes(16));
        $appSecret = 'secret_' . bin2hex(random_bytes(32));

        $business->update([
            'app_id' => $appId,
            'app_secret' => $appSecret,
        ]);

        return $this->successResponse([
            'app_id' => $appId,
            'app_secret' => $appSecret,
        ], 'App credentials regenerated successfully. Please save the app_secret now as it will not be shown again.');
    }
}
