<?php

namespace App\Http\Controllers\Api\V1;

use App\Repositories\Contracts\BusinessRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * @OA\Tag(
 *     name="Businesses",
 *     description="API Endpoints for Business management"
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
     *     summary="Get all businesses",
     *     description="Retrieve a paginated list of all businesses",
     *     operationId="getBusinesses",
     *     tags={"Businesses"},
     *     security={{"bearerAuth":{}}},
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
     *         description="Search businesses by name or email",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object"),
     *             @OA\Property(property="message", type="string", example="Businesses retrieved successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthenticated"
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->get('per_page', 15);
        $search = $request->get('search');

        if ($search) {
            $businesses = $this->businessRepository->search($search, $perPage);
        } else {
            $businesses = $this->businessRepository->all($perPage);
        }

        return $this->successResponse($businesses, 'Businesses retrieved successfully');
    }

    /**
     * @OA\Post(
     *     path="/api/v1/businesses",
     *     summary="Create a new business",
     *     description="Create a new business with the provided data",
     *     operationId="createBusiness",
     *     tags={"Businesses"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name","email"},
     *             @OA\Property(property="name", type="string", example="Acme Corporation"),
     *             @OA\Property(property="email", type="string", format="email", example="contact@acme.com"),
     *             @OA\Property(property="phone_number", type="string", example="+237670000000"),
     *             @OA\Property(property="country_code", type="string", example="+237"),
     *             @OA\Property(property="website", type="string", example="https://acme.com"),
     *             @OA\Property(property="description", type="string", example="A leading technology company"),
     *             @OA\Property(property="address", type="string", example="123 Main St"),
     *             @OA\Property(property="city", type="string", example="Douala"),
     *             @OA\Property(property="country", type="string", example="Cameroon"),
     *             @OA\Property(property="timezone", type="string", example="Africa/Douala"),
     *             @OA\Property(property="settings", type="object", example={"notifications": true})
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Business created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/Business"),
     *             @OA\Property(property="message", type="string", example="Business created successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:businesses,email',
            'phone_number' => 'nullable|string|max:20',
            'country_code' => 'nullable|string|max:10',
            'website' => 'nullable|url|max:255',
            'description' => 'nullable|string',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state_province' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'timezone' => 'nullable|string|max:50',
            'settings' => 'nullable|array',
            'business_hours' => 'nullable|array',
            'is_24_hours' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $business = $this->businessRepository->create($validator->validated());

        return response()->json([
            'success' => true,
            'data' => $business,
            'message' => 'Business created successfully',
        ], 201);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/businesses/{id}",
     *     summary="Get a specific business",
     *     description="Retrieve details of a specific business by ID",
     *     operationId="getBusiness",
     *     tags={"Businesses"},
     *     security={{"bearerAuth":{}}},
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
     *             @OA\Property(property="data", ref="#/components/schemas/Business"),
     *             @OA\Property(property="message", type="string", example="Business retrieved successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Business not found"
     *     )
     * )
     */
    public function show(int $id): JsonResponse
    {
        $business = $this->businessRepository->find($id);

        if (!$business) {
            return $this->errorResponse('Business not found', 404);
        }

        return $this->successResponse($business, 'Business retrieved successfully');
    }

    /**
     * @OA\Put(
     *     path="/api/v1/businesses/{id}",
     *     summary="Update a business",
     *     description="Update an existing business",
     *     operationId="updateBusiness",
     *     tags={"Businesses"},
     *     security={{"bearerAuth":{}}},
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
     *             @OA\Property(property="name", type="string", example="Updated Corporation"),
     *             @OA\Property(property="email", type="string", format="email", example="updated@acme.com"),
     *             @OA\Property(property="phone_number", type="string", example="+237670000001"),
     *             @OA\Property(property="settings", type="object", example={"notifications": false})
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Business updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/Business"),
     *             @OA\Property(property="message", type="string", example="Business updated successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Business not found"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|unique:businesses,email,' . $id,
            'phone_number' => 'nullable|string|max:20',
            'country_code' => 'nullable|string|max:10',
            'website' => 'nullable|url|max:255',
            'description' => 'nullable|string',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state_province' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'timezone' => 'nullable|string|max:50',
            'settings' => 'nullable|array',
            'business_hours' => 'nullable|array',
            'is_24_hours' => 'nullable|boolean',
            'status' => 'nullable|in:active,inactive,suspended',
            'verification_status' => 'nullable|in:pending,verified,rejected',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $business = $this->businessRepository->update($id, $validator->validated());

        if (!$business) {
            return $this->errorResponse('Business not found', 404);
        }

        return $this->successResponse($business, 'Business updated successfully');
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/businesses/{id}",
     *     summary="Delete a business",
     *     description="Soft delete a business",
     *     operationId="deleteBusiness",
     *     tags={"Businesses"},
     *     security={{"bearerAuth":{}}},
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
     *     @OA\Response(
     *         response=404,
     *         description="Business not found"
     *     )
     * )
     */
    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->businessRepository->delete($id);

        if (!$deleted) {
            return $this->errorResponse('Business not found', 404);
        }

        return $this->successResponse(null, 'Business deleted successfully');
    }
}

