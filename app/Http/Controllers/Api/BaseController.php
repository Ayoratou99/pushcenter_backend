<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class BaseController extends Controller
{
    /**
     * Success response method.
     */
    protected function successResponse($data = null, string $message = null, int $statusCode = 200): JsonResponse
    {
        $response = [
            'success' => true,
        ];

        if ($message !== null) {
            $response['message'] = $message;
        }

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

