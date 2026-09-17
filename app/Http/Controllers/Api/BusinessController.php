<?php

namespace App\Http\Controllers\Api;

use App\Repositories\Contracts\BusinessRepositoryInterface;
use App\Support\QueryFilters;
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
     *     path="/api/v1/businesses",
     *     tags={"Businesses"},
     *     security={{"bearerAuth":{}}},
     *     summary="List businesses (applications)",
     *     description="Every filter below can be combined. Managers restricted to a set of applications only see theirs.",
     *     @OA\Parameter(name="search", in="query", description="Name, email, phone, city or app id", @OA\Schema(type="string")),
     *     @OA\Parameter(name="status", in="query", @OA\Schema(type="string", enum={"active","inactive","suspended"})),
     *     @OA\Parameter(name="status_in", in="query", description="Comma separated statuses", @OA\Schema(type="string")),
     *     @OA\Parameter(name="verification_status", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="country", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="city", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="timezone", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="created_from", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="created_to", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="sort_by", in="query", @OA\Schema(type="string", enum={"name","email","status","created_at","updated_at"})),
     *     @OA\Parameter(name="sort_dir", in="query", @OA\Schema(type="string", enum={"asc","desc"})),
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=15)),
     *     @OA\Response(response=200, description="Paginated businesses")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $query = $this->businessRepository->newQuery();

        QueryFilters::restrictToUserBusinesses($query, $request, 'id');
        QueryFilters::exact($query, $request, ['status', 'verification_status', 'country', 'city', 'timezone', 'country_code']);
        QueryFilters::inList($query, $request, ['status', 'verification_status', 'country']);
        QueryFilters::booleans($query, $request, ['is_24_hours']);
        QueryFilters::search($query, $request->input('search'), [
            'name', 'email', 'phone_number', 'city', 'country', 'app_id',
        ]);
        QueryFilters::dateRange($query, $request, 'created_at');

        QueryFilters::sort($query, $request, ['name', 'email', 'status', 'city', 'country', 'created_at', 'updated_at']);

        return $this->successResponse($query->paginate(QueryFilters::perPage($request)));
    }

    /**
     * @OA\Post(
     *     path="/api/v1/businesses",
     *     tags={"Businesses"},
     *     security={{"bearerAuth":{}}},
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
            'country_code' => 'nullable|string|max:10',
            'website' => 'nullable|url',
            'description' => 'nullable|string',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'status' => 'nullable|in:active,inactive,suspended',
            'timezone' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $business = $this->businessRepository->create($request->all());

        // app_id / app_secret are generated by the model on creation; the secret
        // is echoed once here so the UI can show it to the user straight away.
        return $this->createdResponse([
            'business' => $business,
            'credentials' => [
                'app_id' => $business->app_id,
                'app_secret' => $business->app_secret,
            ],
        ], 'Application created successfully. Copy the app secret now, it is shown here for convenience.');
    }

    /**
     * @OA\Get(
     *     path="/api/v1/businesses/{id}",
     *     tags={"Businesses"},
     *     security={{"bearerAuth":{}}},
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
        if ($deny = $this->denyUnlessRecordAccessible(\App\Models\Business::class, $id, 'id')) {
            return $deny;
        }

        $business = $this->businessRepository->find($id);

        if (!$business) {
            return $this->notFoundResponse('Business');
        }

        return $this->successResponse($business);
    }

    /**
     * @OA\Put(
     *     path="/api/v1/businesses/{id}",
     *     tags={"Businesses"},
     *     security={{"bearerAuth":{}}},
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
        if ($deny = $this->denyUnlessRecordAccessible(\App\Models\Business::class, $id, 'id')) {
            return $deny;
        }

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
     *     path="/api/v1/businesses/{id}",
     *     tags={"Businesses"},
     *     security={{"bearerAuth":{}}},
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
        if ($deny = $this->denyUnlessRecordAccessible(\App\Models\Business::class, $id, 'id')) {
            return $deny;
        }

        $deleted = $this->businessRepository->delete($id);

        if (!$deleted) {
            return $this->notFoundResponse('Business');
        }

        return $this->deletedResponse('Business deleted successfully');
    }

    /**
     * @OA\Get(
     *     path="/api/v1/businesses/{id}/stats",
     *     tags={"Businesses"},
     *     security={{"bearerAuth":{}}},
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
        if ($deny = $this->denyUnlessRecordAccessible(\App\Models\Business::class, $id, 'id')) {
            return $deny;
        }

        $stats = $this->businessRepository->getStats($id);

        if (!$stats) {
            return $this->notFoundResponse('Business');
        }

        return $this->successResponse($stats);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/businesses/{id}/regenerate-credentials",
     *     tags={"Businesses"},
     *     security={{"bearerAuth":{}}},
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
        if ($deny = $this->denyUnlessRecordAccessible(\App\Models\Business::class, $id, 'id')) {
            return $deny;
        }

        $business = $this->businessRepository->find($id);

        if (!$business) {
            return $this->notFoundResponse('Business');
        }

        // Generate new credentials
        $appId = \App\Models\Business::generateAppId();
        $appSecret = \App\Models\Business::generateAppSecret();

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
