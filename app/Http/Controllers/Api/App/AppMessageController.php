<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Api\BaseController;
use App\Http\Middleware\AuthenticateApplication;
use App\Jobs\SendMessagesJob;
use App\Models\Business;
use App\Models\EmailMessage;
use App\Models\EmailTemplate;
use App\Models\Message;
use App\Services\TemplateRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Endpoints an application calls with its own token.
 *
 * The business is always taken from the token, never from the payload.
 *
 * @OA\Tag(name="Application API", description="Machine-to-machine API")
 */
class AppMessageController extends BaseController
{
    public function __construct(private TemplateRenderer $renderer)
    {
    }

    /**
     * @OA\Post(
     *     path="/api/v1/app/messages/email",
     *     tags={"Application API"},
     *     security={{"bearerAuth":{}}},
     *     summary="Queue a templated email",
     *     description="Renders one of the application's active email templates and queues it for delivery through the application's SMTP settings.",
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"template_id", "recipient_email"},
     *         @OA\Property(property="template_id", type="integer", description="Id of an email template owned by this application"),
     *         @OA\Property(property="recipient_email", type="string", format="email"),
     *         @OA\Property(property="recipient_name", type="string"),
     *         @OA\Property(property="variables", type="object", description="Values for the {{placeholders}} of the template"),
     *         @OA\Property(property="cc", type="array", @OA\Items(type="string", format="email")),
     *         @OA\Property(property="bcc", type="array", @OA\Items(type="string", format="email")),
     *         @OA\Property(property="campaign_id", type="string")
     *     )),
     *     @OA\Response(response=201, description="Message queued"),
     *     @OA\Response(response=401, description="Invalid application token"),
     *     @OA\Response(response=422, description="Validation error, unknown template or missing variables"),
     *     @OA\Response(response=503, description="No usable SMTP configuration")
     * )
     */
    public function sendEmail(Request $request): JsonResponse
    {
        $business = $this->application($request);

        $validator = Validator::make($request->all(), [
            'template_id' => 'required|integer',
            'recipient_email' => 'required|email|max:255',
            'recipient_name' => 'nullable|string|max:255',
            'variables' => 'nullable|array',
            'cc' => 'nullable|array',
            'cc.*' => 'email',
            'bcc' => 'nullable|array',
            'bcc.*' => 'email',
            'campaign_id' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        // Scoped to the token's application: another application's template is
        // simply not found.
        $template = EmailTemplate::where('business_id', $business->getKey())
            ->whereKey($request->input('template_id'))
            ->first();

        if (! $template) {
            return $this->errorResponse('Unknown email template for this application.', [
                'template_id' => ['This template does not belong to your application.'],
            ], 422);
        }

        if (! $template->is_active || $template->status !== 'active') {
            return $this->errorResponse('This email template is not active.', [
                'template_id' => ['Activate the template before sending with it.'],
            ], 422);
        }

        // Refuse early rather than queueing something that can only fail later.
        if (! $this->resolveSmtpSetting($business)) {
            return $this->errorResponse(
                'No active SMTP configuration for this application.',
                null,
                503
            );
        }

        $variables = (array) $request->input('variables', []);

        $missing = $this->renderer->missingVariables(
            [$template->subject, $template->html, $template->plain_text],
            $variables
        );

        if ($missing) {
            return $this->errorResponse('Missing template variables: ' . implode(', ', $missing), [
                'variables' => ['Provide a value for: ' . implode(', ', $missing)],
            ], 422);
        }

        $message = DB::transaction(function () use ($business, $template, $request, $variables) {
            $message = Message::create([
                'business_id' => $business->getKey(),
                'message_id' => (string) Str::uuid(),
                'message_type' => 'email',
                'status' => 'queued',
                'campaign_id' => $request->input('campaign_id'),
            ]);

            EmailMessage::create([
                'message_id' => $message->id,
                'email_template_id' => $template->id,
                'is_template' => true,
                'recipient_email' => $request->input('recipient_email'),
                'recipient_name' => $request->input('recipient_name'),
                'subject' => $this->renderer->render($template->subject, $variables),
                'content' => $this->renderer->render($template->html, $variables),
                'template_variables' => $variables,
                'cc' => $request->input('cc'),
                'bcc' => $request->input('bcc'),
            ]);

            return $message;
        });

        // Horizon picks the `emails` queue up (see config/horizon.php).
        SendMessagesJob::dispatch($message->id);

        $template->markAsUsed();

        return $this->createdResponse([
            'message_id' => $message->message_id,
            'id' => $message->id,
            'status' => $message->status,
            'message_type' => $message->message_type,
            'template' => ['id' => $template->id, 'name' => $template->name],
            'recipient_email' => $request->input('recipient_email'),
            'queued_at' => $message->created_at?->toIso8601String(),
        ], 'Email queued for delivery');
    }

    /**
     * @OA\Get(
     *     path="/api/v1/app/messages/{id}",
     *     tags={"Application API"},
     *     security={{"bearerAuth":{}}},
     *     summary="Delivery status of one of the application's messages",
     *     @OA\Parameter(name="id", in="path", required=true, description="Numeric id or message_id (uuid)", @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Message status"),
     *     @OA\Response(response=404, description="Not found for this application")
     * )
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $business = $this->application($request);

        $message = Message::with('emailMessage')
            ->where('business_id', $business->getKey())
            ->where(fn ($query) => $query->where('message_id', $id)->orWhere('id', $id))
            ->first();

        if (! $message) {
            return $this->notFoundResponse('Message');
        }

        return $this->successResponse([
            'id' => $message->id,
            'message_id' => $message->message_id,
            'message_type' => $message->message_type,
            'status' => $message->status,
            'error_message' => $message->error_message,
            'retry_count' => $message->retry_count,
            'sent_at' => $message->sent_at?->toIso8601String(),
            'delivered_at' => $message->delivered_at?->toIso8601String(),
            'failed_at' => $message->failed_at?->toIso8601String(),
            'recipient' => $message->emailMessage?->recipient_email,
            'subject' => $message->emailMessage?->subject,
            'webhook' => [
                'status' => $message->webhook_status,
                'error' => $message->webhook_error,
                'attempts' => $message->webhook_attempts,
                'last_attempt_at' => $message->webhook_last_attempt_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/app/templates/email",
     *     tags={"Application API"},
     *     security={{"bearerAuth":{}}},
     *     summary="Email templates available to this application",
     *     @OA\Response(response=200, description="Active email templates")
     * )
     */
    public function emailTemplates(Request $request): JsonResponse
    {
        $business = $this->application($request);

        $templates = EmailTemplate::where('business_id', $business->getKey())
            ->where('is_active', true)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'subject', 'description', 'variables', 'category']);

        return $this->successResponse($templates);
    }

    /* ------------------------------------------------------------------ */

    private function application(Request $request): Business
    {
        return $request->attributes->get(AuthenticateApplication::ATTRIBUTE);
    }

    /**
     * The SMTP configuration a message from this application would go through.
     */
    private function resolveSmtpSetting(Business $business)
    {
        return $business->smtpSettings()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->first();
    }
}
