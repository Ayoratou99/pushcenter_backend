<?php

use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\TemplateController;
use App\Http\Controllers\Api\EmailTemplateController;
use App\Http\Controllers\Api\SmsTemplateController;
use App\Http\Controllers\Api\WhatsappTemplateController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\PublicBusinessController;
use App\Http\Controllers\Api\PublicFacebookController;
use App\Http\Controllers\Api\SmtpSettingController;
use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group.
|
*/

// Health check endpoint (no authentication required)
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'aninfpush',
        'version' => '1.0.0',
        'timestamp' => now()->toIso8601String(),
    ]);
});

/*
|--------------------------------------------------------------------------
| API Version 1 Routes
|--------------------------------------------------------------------------
*/

// Authentication routes (no auth required for login/refresh)
Route::prefix('v1/auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
});

// Protected API routes (authentication required)
Route::prefix('v1')->middleware(['auth:api'])->group(function () {
    // Auth routes (protected)
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/user', [AuthController::class, 'user']);
    });
    
    // Business Resource Routes (using main BusinessController)
    // Removed duplicate V1BusinessController that was causing Swagger conflicts

    // Messages Routes
    // TODO: Implement MessageController
    // Route::apiResource('messages', MessageController::class);
    
    // Email Templates Routes
    // TODO: Implement EmailTemplateController
    // Route::apiResource('email-templates', EmailTemplateController::class);
    
    // SMS Templates Routes
    // TODO: Implement SmsTemplateController
    // Route::apiResource('sms-templates', SmsTemplateController::class);
    
    // WhatsApp Templates Routes
    // TODO: Implement WhatsappTemplateController
    // Route::apiResource('whatsapp-templates', WhatsappTemplateController::class);
    
    // WhatsApp Phone Numbers Routes
    // TODO: Implement WhatsappPhoneNumberController
    // Route::apiResource('whatsapp-phone-numbers', WhatsappPhoneNumberController::class);
    
    // SMTP Settings Routes
    // TODO: Implement SmtpSettingController
    // Route::apiResource('smtp-settings', SmtpSettingController::class);
    
    // SMS Settings Routes
    // TODO: Implement SmsSettingController
    // Route::apiResource('sms-settings', SmsSettingController::class);
    
    // Facebook Settings Routes
    // TODO: Implement FacebookSettingController
    // Route::apiResource('facebook-settings', FacebookSettingController::class);
});

/*
|--------------------------------------------------------------------------
| API Routes (No Auth - For Development, Enable auth:sanctum later)
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->middleware(['auth:api'])->group(function () {
    
    // Dashboard Routes
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('/dashboard/recent-messages', [DashboardController::class, 'recentMessages']);
    Route::get('/dashboard/message-trends', [DashboardController::class, 'messageTrends']);
    Route::get('/dashboard/cost-analysis', [DashboardController::class, 'costAnalysis']);

    // Business Resource Routes
    Route::apiResource('businesses', BusinessController::class);
    Route::get('/businesses/{id}/stats', [BusinessController::class, 'stats']);
    Route::post('/businesses/{id}/regenerate-credentials', [BusinessController::class, 'regenerateCredentials']);

    // Message Resource Routes
    Route::apiResource('messages', MessageController::class);
    Route::post('/messages/whatsapp', [MessageController::class, 'sendWhatsApp']);
    Route::post('/messages/sms', [MessageController::class, 'sendSms']);
    Route::post('/messages/email', [MessageController::class, 'sendEmail']);
    Route::post('/messages/{id}/retry', [MessageController::class, 'retry']);
    Route::post('/messages/{id}/cancel', [MessageController::class, 'cancel']);
    Route::get('/messages/stats', [MessageController::class, 'stats']);

    // Template Resource Routes (Generic - Optional)
    Route::apiResource('templates', TemplateController::class);
    Route::post('/templates/{id}/activate', [TemplateController::class, 'activate']);
    Route::post('/templates/{id}/deactivate', [TemplateController::class, 'deactivate']);
    
    // Email Template Routes
    Route::apiResource('email-templates', EmailTemplateController::class);
    Route::post('/email-templates/{id}/activate', [EmailTemplateController::class, 'activate']);
    Route::post('/email-templates/{id}/deactivate', [EmailTemplateController::class, 'deactivate']);
    
    // SMS Template Routes
    Route::apiResource('sms-templates', SmsTemplateController::class);
    Route::post('/sms-templates/{id}/activate', [SmsTemplateController::class, 'activate']);
    Route::post('/sms-templates/{id}/deactivate', [SmsTemplateController::class, 'deactivate']);
    
    // WhatsApp Template Routes
    Route::apiResource('whatsapp-templates', WhatsappTemplateController::class);
    Route::post('/whatsapp-templates/{id}/activate', [WhatsappTemplateController::class, 'activate']);
    Route::post('/whatsapp-templates/{id}/deactivate', [WhatsappTemplateController::class, 'deactivate']);
    Route::post('/whatsapp-templates/{id}/submit', [WhatsappTemplateController::class, 'submitForApproval']);

    // SMTP Settings Routes
    Route::get('/smtp-settings/base', [SmtpSettingController::class, 'getBaseConfigurations']);
    Route::get('/businesses/{businessId}/smtp-settings', [SmtpSettingController::class, 'index']);
    Route::post('/businesses/{businessId}/smtp-settings', [SmtpSettingController::class, 'store']);
    Route::get('/businesses/{businessId}/smtp-settings/{id}', [SmtpSettingController::class, 'show']);
    Route::put('/businesses/{businessId}/smtp-settings/{id}', [SmtpSettingController::class, 'update']);
    Route::delete('/businesses/{businessId}/smtp-settings/{id}', [SmtpSettingController::class, 'destroy']);
    Route::post('/businesses/{businessId}/smtp-settings/{id}/test', [SmtpSettingController::class, 'test']);
});

/*
|--------------------------------------------------------------------------
| Additional API Endpoints
|--------------------------------------------------------------------------
*/

// Webhook endpoints (no authentication, will use webhook verification)
Route::prefix('webhooks')->group(function () {
    // WhatsApp webhook
    // Route::match(['get', 'post'], '/whatsapp', [WhatsAppWebhookController::class, 'handle']);
    
    // SMS delivery status webhook
    // Route::post('/sms/status', [SmsWebhookController::class, 'handleStatus']);
});

// Public routes (no authentication required)
Route::prefix('public')->group(function () {
    // Get business info for public connection
    Route::get('/businesses/{id}', [PublicBusinessController::class, 'show']);
    
    // Connect Facebook account (public endpoint)
    Route::post('/businesses/{id}/facebook-settings/connect', [PublicFacebookController::class, 'connect']);
});

