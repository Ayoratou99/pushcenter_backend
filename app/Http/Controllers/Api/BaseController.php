<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class BaseController extends Controller
{
    /**
     * Guard a single record against a manager restricted to other applications.
     *
     * Returns a 403 response to hand back, or null when access is allowed. The
     * list endpoints filter with QueryFilters::restrictToUserBusinesses(); this
     * is its counterpart for show/update/delete, where the id comes from the URL.
     */
    protected function denyUnlessBusinessAccessible($businessId): ?JsonResponse
    {
        $user = request()->user();

        if (! $user || $user->canAccessBusiness($businessId)) {
            return null;
        }

        return $this->errorResponse('You do not have access to this application.', null, 403);
    }

    /**
     * Same guard, for a record identified by its primary key.
     *
     * An unknown id is left alone so the controller can answer its usual 404.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @param  string  $column  Column holding the business id ('id' on Business itself)
     */
    protected function denyUnlessRecordAccessible(string $modelClass, $id, string $column = 'business_id'): ?JsonResponse
    {
        $user = request()->user();

        if (! $user || $user->hasUnrestrictedAccess()) {
            return null;
        }

        $businessId = $modelClass::withTrashed()->whereKey($id)->value($column);

        if ($businessId === null) {
            return null;
        }

        return $this->denyUnlessBusinessAccessible($businessId);
    }

    /**
     * Success response method.
     */
    protected function successResponse($data = null, ?string $message = null, int $statusCode = 200): JsonResponse
    {
        $response = [
            'success' => true,
            'message' => $message ?? 'Operation successful',
        ];

        if ($data !== null) {
            $response['data'] = $data;
        }

        return response()->json($response, $statusCode);
    }

    /**
     * Error response method.
     */
    protected function errorResponse(string $message, $errors = null, int $statusCode = 400): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $statusCode);
    }

    /**
     * Validation error response.
     */
    protected function validationErrorResponse($errors): JsonResponse
    {
        return $this->errorResponse('Validation error', $errors, 422);
    }

    /**
     * Not found response.
     */
    protected function notFoundResponse(string $resource = 'Resource'): JsonResponse
    {
        return $this->errorResponse("{$resource} not found", null, 404);
    }

    /**
     * Created response.
     */
    protected function createdResponse($data, string $message = 'Created successfully'): JsonResponse
    {
        return $this->successResponse($data, $message, 201);
    }

    /**
     * Deleted response.
     */
    protected function deletedResponse(string $message = 'Deleted successfully'): JsonResponse
    {
        return $this->successResponse(null, $message);
    }

    /**
     * Updated response.
     */
    protected function updatedResponse($data, string $message = 'Updated successfully'): JsonResponse
    {
        return $this->successResponse($data, $message);
    }
}

