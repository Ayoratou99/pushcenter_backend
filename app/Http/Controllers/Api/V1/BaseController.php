<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;

class BaseController extends Controller
{
    //
    protected function successResponse($data, $message = null, $code = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data
        ], $code);
    }

    protected function errorResponse($message = null, $code = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $code);
    }

    protected function log($message,$level = 'info', $context = []): void
    {
        Log::{$level}($message, $context);
    }
}
