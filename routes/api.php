<?php

use App\Http\Controllers\Api\App\AppMessageController;
use App\Http\Controllers\Api\App\AppTokenController;
use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmailTemplateController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\SmsTemplateController;
use App\Http\Controllers\Api\SmtpSettingController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\TemplateController;
use App\Http\Controllers\Api\TelegramSettingController;
use App\Http\Controllers\Api\TelegramTemplateController;
use App\Http\Controllers\Api\TemplateTransferController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WhatsappSettingController;
use App\Http\Controllers\Api\WhatsappTemplateController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Authentication is internal: POST /v1/auth/login returns a JWT access token
| plus a refresh token. Google Authenticator is mandatory, so most routes sit
| behind auth:api + the `2fa` middleware.
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
| Authentication (public)
|--------------------------------------------------------------------------
*/
Route::prefix('v1/auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/login/two-factor', [AuthController::class, 'loginTwoFactor']);
    Route::post('/refresh', [AuthController::class, 'refresh']);

    // Enrolment during the very first login: authorised by the short lived
    // setup_token handed out by /login, so no access token exists yet.
    Route::post('/two-factor/setup', [AuthController::class, 'setupTwoFactor']);
    Route::post('/two-factor/confirm', [AuthController::class, 'confirmTwoFactor']);
});

/*
|--------------------------------------------------------------------------
| Application API (machine to machine)
|--------------------------------------------------------------------------
|
| An application exchanges its app_id/app_secret for a short lived token, then
| uses it on /v1/app/*. The token carries the application, so these endpoints
| never take a business id from the payload.
|
*/
Route::post('v1/auth/token', [AppTokenController::class, 'issue']);

Route::prefix('v1/app')->middleware('auth.app')->group(function () {
    Route::post('/messages/email', [AppMessageController::class, 'sendEmail']);
    Route::post('/messages/whatsapp', [AppMessageController::class, 'sendWhatsapp']);
    Route::post('/messages/telegram', [AppMessageController::class, 'sendTelegram']);
    Route::get('/messages/{id}', [AppMessageController::class, 'show']);
    Route::get('/templates/email', [AppMessageController::class, 'emailTemplates']);
    Route::get('/templates/whatsapp', [AppMessageController::class, 'whatsappTemplates']);
    Route::get('/templates/telegram', [AppMessageController::class, 'telegramTemplates']);
    Route::post('/telegram/invitations', [AppMessageController::class, 'telegramInvitation']);
    Route::get('/telegram/subscribers/{externalRef}', [AppMessageController::class, 'telegramSubscriber']);
});

/*
|--------------------------------------------------------------------------
| Authenticated account routes (two-factor not required yet)
|--------------------------------------------------------------------------
*/
Route::prefix('v1/auth')->middleware(['auth:api', 'activity'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::put('/password', [AuthController::class, 'updatePassword']);
    Route::post('/two-factor/disable', [AuthController::class, 'disableTwoFactor']);
    Route::post('/two-factor/recovery-codes', [AuthController::class, 'regenerateRecoveryCodes']);
});

/*
|--------------------------------------------------------------------------
| Application routes (authenticated + two-factor confirmed)
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->middleware(['auth:api', '2fa', 'activity'])->group(function () {

    /* -------------------------------- Users ------------------------------- */
    // Admins manage everyone; a manager only the managers of its own
    // applications (enforced in UserController).
    Route::get('/users/options/businesses', [UserController::class, 'businessOptions']);
    Route::apiResource('users', UserController::class);
    Route::put('/users/{id}/businesses', [UserController::class, 'assignBusinesses']);
    Route::post('/users/{id}/reset-two-factor', [UserController::class, 'resetTwoFactor']);

    /* ------------------------------- Activity ----------------------------- */
    Route::get('/activities', [ActivityController::class, 'index']);
    Route::get('/activities/actions', [ActivityController::class, 'actions']);

    /* -------------------------------- System ------------------------------ */
    Route::get('/system/horizon', [SystemController::class, 'horizon']);

    /* ---------------------------- Notifications --------------------------- */
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read', [NotificationController::class, 'markAllRead']);

    /* ------------------------------ Dashboard ----------------------------- */
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('/dashboard/recent-messages', [DashboardController::class, 'recentMessages']);
    Route::get('/dashboard/message-trends', [DashboardController::class, 'messageTrends']);
    Route::get('/dashboard/cost-analysis', [DashboardController::class, 'costAnalysis']);

    /* ------------------------------ Businesses ---------------------------- */
    Route::get('/businesses/{id}/stats', [BusinessController::class, 'stats']);
    Route::post('/businesses/{id}/regenerate-credentials', [BusinessController::class, 'regenerateCredentials']);
    // Creating or deleting an application is for administrators; a manager
    // edits its own (BusinessController checks the scope).
    Route::apiResource('businesses', BusinessController::class)->except(['store', 'destroy']);
    Route::middleware('role:admin')->group(function () {
        Route::post('/businesses', [BusinessController::class, 'store']);
        Route::delete('/businesses/{business}', [BusinessController::class, 'destroy']);
    });

    /* ------------------------------- Messages ----------------------------- */
    // Read side of the console. Messages are sent by the applications
    // themselves, through POST /v1/app/messages/* (AppMessageController).
    Route::get('/messages/stats', [MessageController::class, 'stats']);
    Route::post('/messages/{id}/retry', [MessageController::class, 'retry']);
    Route::post('/messages/{id}/cancel', [MessageController::class, 'cancel']);
    Route::apiResource('messages', MessageController::class)->only(['index', 'show', 'destroy']);

    /* --------------------- Template export / import ----------------------- */
    // Declared before the resource routes so /templates/export is not caught
    // by /templates/{template}.
    Route::post('/templates/export', [TemplateTransferController::class, 'exportMany']);
    Route::post('/templates/import/preview', [TemplateTransferController::class, 'preview']);
    Route::post('/templates/import', [TemplateTransferController::class, 'import']);
    Route::get('/templates/{type}/{id}/export', [TemplateTransferController::class, 'export'])
        ->where('type', 'email|sms|whatsapp|telegram');
    Route::post('/templates/{type}/{id}/duplicate', [TemplateTransferController::class, 'duplicate'])
        ->where('type', 'email|sms|whatsapp|telegram');

    /* ------------------------------ Templates ----------------------------- */
    Route::post('/templates/{id}/activate', [TemplateController::class, 'activate']);
    Route::post('/templates/{id}/deactivate', [TemplateController::class, 'deactivate']);
    Route::apiResource('templates', TemplateController::class);

    Route::post('/email-templates/{id}/activate', [EmailTemplateController::class, 'activate']);
    Route::post('/email-templates/{id}/deactivate', [EmailTemplateController::class, 'deactivate']);
    Route::apiResource('email-templates', EmailTemplateController::class);

    Route::post('/sms-templates/{id}/activate', [SmsTemplateController::class, 'activate']);
    Route::post('/sms-templates/{id}/deactivate', [SmsTemplateController::class, 'deactivate']);
    Route::apiResource('sms-templates', SmsTemplateController::class);

    // WhatsApp templates are submitted to Meta and synchronised through AyosPush.
    Route::post('/whatsapp-templates/media', [WhatsappTemplateController::class, 'uploadMedia']);
    Route::post('/whatsapp-templates/{id}/activate', [WhatsappTemplateController::class, 'activate']);
    Route::post('/whatsapp-templates/{id}/deactivate', [WhatsappTemplateController::class, 'deactivate']);
    Route::post('/whatsapp-templates/{id}/submit', [WhatsappTemplateController::class, 'submitForApproval']);
    Route::post('/whatsapp-templates/{id}/sync', [WhatsappTemplateController::class, 'sync']);
    Route::post('/businesses/{businessId}/whatsapp-templates/sync', [WhatsappTemplateController::class, 'syncBusiness'])
        ->whereNumber('businessId');
    Route::apiResource('whatsapp-templates', WhatsappTemplateController::class);

    /* ----------------------- WhatsApp settings (AyosPush) ------------------ */
    Route::get('/businesses/{businessId}/whatsapp-settings', [WhatsappSettingController::class, 'show'])
        ->whereNumber('businessId');
    Route::put('/businesses/{businessId}/whatsapp-settings', [WhatsappSettingController::class, 'update'])
        ->whereNumber('businessId');
    Route::delete('/businesses/{businessId}/whatsapp-settings', [WhatsappSettingController::class, 'destroy'])
        ->whereNumber('businessId');
    Route::post('/businesses/{businessId}/whatsapp-settings/test', [WhatsappSettingController::class, 'test'])
        ->whereNumber('businessId');

    /* -------------------------------- Telegram ---------------------------- */
    Route::get('/businesses/{businessId}/telegram-settings', [TelegramSettingController::class, 'show'])->whereNumber('businessId');
    Route::put('/businesses/{businessId}/telegram-settings', [TelegramSettingController::class, 'update'])->whereNumber('businessId');
    Route::delete('/businesses/{businessId}/telegram-settings', [TelegramSettingController::class, 'destroy'])->whereNumber('businessId');
    Route::post('/businesses/{businessId}/telegram-settings/test', [TelegramSettingController::class, 'test'])->whereNumber('businessId');
    Route::post('/businesses/{businessId}/telegram-settings/poll', [TelegramSettingController::class, 'poll'])->whereNumber('businessId');
    Route::get('/businesses/{businessId}/telegram-subscribers', [TelegramSettingController::class, 'subscribers'])->whereNumber('businessId');
    Route::delete('/businesses/{businessId}/telegram-subscribers/{id}', [TelegramSettingController::class, 'destroySubscriber'])->whereNumber('businessId');
    Route::get('/businesses/{businessId}/telegram-invitations', [TelegramSettingController::class, 'invitations'])->whereNumber('businessId');
    Route::post('/businesses/{businessId}/telegram-invitations', [TelegramSettingController::class, 'storeInvitation'])->whereNumber('businessId');

    Route::post('/telegram-templates/{id}/media', [TelegramTemplateController::class, 'uploadMedia'])->whereNumber('id');
    Route::get('/telegram-templates/{id}/media', [TelegramTemplateController::class, 'media'])->whereNumber('id');
    Route::delete('/telegram-templates/{id}/media', [TelegramTemplateController::class, 'destroyMedia'])->whereNumber('id');
    Route::post('/telegram-templates/{id}/activate', [TelegramTemplateController::class, 'activate']);
    Route::post('/telegram-templates/{id}/deactivate', [TelegramTemplateController::class, 'deactivate']);
    Route::apiResource('telegram-templates', TelegramTemplateController::class);

    /* ---------------------------- SMTP settings --------------------------- */
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
| Webhooks (no authentication, verified per provider)
|--------------------------------------------------------------------------
*/
Route::prefix('webhooks')->group(function () {
    // WhatsApp webhook
    // Route::match(['get', 'post'], '/whatsapp', [WhatsAppWebhookController::class, 'handle']);

    // SMS delivery status webhook
    // Route::post('/sms/status', [SmsWebhookController::class, 'handleStatus']);
});
