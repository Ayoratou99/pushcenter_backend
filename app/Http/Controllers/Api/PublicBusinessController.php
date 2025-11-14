<?php

namespace App\Http\Controllers\Api;

use App\Models\Business;
use Illuminate\Http\JsonResponse;

/**
 * @OA\Tag(
 *     name="Public",
 *     description="Public API endpoints (no authentication required)"
 * )
 */
class PublicBusinessController extends BaseController
{
    /**
     * @OA\Get(
     *     path="/api/public/businesses/{id}",
     *     tags={"Public"},
     *     summary="Get public business information",
     *     description="Get basic business information for public connection page (no authentication required)",
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
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="Acme Corporation"),
     *                 @OA\Property(property="status", type="string", example="active")
     *             )
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
        $business = Business::where('status', 'active')
            ->select(['id', 'name', 'status'])
            ->find($id);

        if (!$business) {
            return $this->notFoundResponse('Business not found or is not active');
        }

        return $this->successResponse($business);
    }
}

