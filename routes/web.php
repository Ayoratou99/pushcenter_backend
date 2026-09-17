<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes - API Only Application
|--------------------------------------------------------------------------
|
| This is an API-only application. All API routes are defined in routes/api.php.
| Authentication is handled internally via JWT access tokens (see routes/api.php).
|
| No web routes are needed for this application.
|
*/

// Redirect root to API health check
Route::get('/', function () {
    return response()->json([
        'service' => 'AninfPush API',
        'version' => '1.0.0',
        'documentation' => url('/api/documentation'),
        'health' => url('/api/health'),
        'horizon' => url('/horizon'),
        'message' => 'This is an API-only service. Please use the API endpoints.',
    ]);
});
