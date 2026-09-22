<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Api\BaseController;
use App\Http\Middleware\AuthenticateApplication;
use App\Jobs\SendMessagesJob;
use App\Models\Business;
use App\Models\EmailMessage;
use App\Models\EmailTemplate;
use App\Http\Controllers\Api\TelegramSettingController;
use App\Models\Message;
use App\Models\TelegramMessage;
use App\Models\TelegramSetting;
use App\Models\TelegramSubscriber;
use App\Models\TelegramTemplate;
use App\Models\WhatsAppMessage;
use App\Models\WhatsappSetting;
use App\Models\WhatsappTemplate;
use App\Services\Telegram\TelegramComposer;
use App\Services\TemplateRenderer;
use App\Support\WhatsappVariables;
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
    public function __construct(private TemplateRenderer $renderer, private TelegramComposer $telegram)
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
     * @OA\Post(
     *     path="/api/v1/app/messages/whatsapp",
     *     tags={"Application API"},
     *     security={{"bearerAuth":{}}},
     *     summary="Queue a WhatsApp template message",
     *     description="Sends one of the application's approved WhatsApp templates through AyosPush (POST /v1/messages/template), from the sender number chosen in the application's WhatsApp settings. The message is `queued`, then `sending` once AyosPush accepted it, then `sent` (handed to Meta) or `failed` with the reason. AyosPush does not report delivered/read receipts. Variables are keyed by their number, body first: {""1"": ""Awa"", ""2"": ""CMD-001""} (a list is accepted too).",
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"recipient_number"},
     *         @OA\Property(property="template_id", type="integer", description="Id of an approved WhatsApp template of this application (or give template_name)"),
     *         @OA\Property(property="template_name", type="string", description="Name of the template in AninfPush, as listed by GET /v1/app/templates/whatsapp"),
     *         @OA\Property(property="language", type="string", enum={"fr", "en"}, description="With template_name, when the name exists in both languages"),
     *         @OA\Property(property="recipient_number", type="string", example="+24177123456", description="International format"),
     *         @OA\Property(property="recipient_name", type="string"),
     *         @OA\Property(property="variables", type="object", example={"1": "Awa", "2": "CMD-001"}),
     *         @OA\Property(property="campaign_id", type="string")
     *     )),
     *     @OA\Response(response=201, description="Message queued"),
     *     @OA\Response(response=401, description="Invalid application token"),
     *     @OA\Response(response=422, description="Validation error, unknown or unapproved template, missing or invalid variables"),
     *     @OA\Response(response=503, description="WhatsApp not configured for this application (AyosPush key or sender number)")
     * )
     */
    public function sendWhatsapp(Request $request): JsonResponse
    {
        $business = $this->application($request);

        $validator = Validator::make($request->all(), [
            'template_id' => 'required_without:template_name|nullable|integer',
            'template_name' => 'required_without:template_id|nullable|string|max:255',
            'language' => 'nullable|string|max:10',
            'recipient_number' => 'required|string|max:32',
            'recipient_name' => 'nullable|string|max:255',
            'variables' => 'nullable|array',
            'campaign_id' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $recipient = WhatsappVariables::normalizeNumber((string) $request->input('recipient_number'));

        if (! $recipient) {
            return $this->validationErrorResponse([
                'recipient_number' => ['Use the international format, with the country code: +24177123456.'],
            ]);
        }

        // Scoped to the token's application: another application's template is
        // simply not found.
        $templates = WhatsappTemplate::where('business_id', $business->getKey())
            ->when($request->filled('template_id'), fn ($q) => $q->whereKey($request->input('template_id')))
            ->when(! $request->filled('template_id'), fn ($q) => $q->where('name', $request->input('template_name')))
            ->when($request->filled('language'), fn ($q) => $q->where('language', $request->input('language')))
            ->get();

        if ($templates->isEmpty()) {
            return $this->errorResponse('Unknown WhatsApp template for this application.', [
                'template' => ['This template does not belong to your application.'],
            ], 422);
        }

        if ($templates->count() > 1) {
            return $this->errorResponse('This template name exists in several languages.', [
                'language' => ['Give the language: ' . $templates->pluck('language')->implode(', ') . '.'],
            ], 422);
        }

        /** @var WhatsappTemplate $template */
        $template = $templates->first();

        if ($template->status !== 'approved' || ! $template->provider_template_name) {
            return $this->errorResponse("This WhatsApp template is not approved by Meta (status: {$template->status}).", [
                'template' => ['Only approved templates can be sent.'],
            ], 422);
        }

        if (! $template->is_active) {
            return $this->errorResponse('This WhatsApp template is not active.', [
                'template' => ['Activate the template before sending with it.'],
            ], 422);
        }

        // Refuse early rather than queueing something that can only fail later.
        $settings = WhatsappSetting::where('business_id', $business->getKey())->first();

        if (! $settings || $settings->test_status !== 'success') {
            return $this->errorResponse('WhatsApp is not configured for this application.', null, 503);
        }

        if (! $settings->default_phone_number_id) {
            return $this->errorResponse('No WhatsApp sender number is selected for this application.', null, 503);
        }

        $variables = WhatsappVariables::normalize((array) $request->input('variables', []));

        if ($problems = WhatsappVariables::problems($template, $variables)) {
            return $this->errorResponse('Invalid template variables: ' . implode(' ', $problems), [
                'variables' => array_values($problems),
            ], 422);
        }

        // Only the values the template uses are sent.
        $variables = array_intersect_key($variables, array_flip(WhatsappVariables::expected($template)));

        $message = DB::transaction(function () use ($business, $template, $request, $recipient, $variables, $settings) {
            $message = Message::create([
                'business_id' => $business->getKey(),
                'message_id' => (string) Str::uuid(),
                'message_type' => 'whatsapp',
                'status' => 'queued',
                'campaign_id' => $request->input('campaign_id'),
            ]);

            WhatsAppMessage::create([
                'message_id' => $message->id,
                'whatsapp_template_id' => $template->id,
                'is_template' => true,
                'recipient_number' => $recipient,
                'recipient_name' => $request->input('recipient_name'),
                'content' => WhatsappVariables::render((string) $template->body, $variables),
                'template_variables' => $variables,
                'sender_phone_number_id' => $settings->default_phone_number_id,
                'provider_template_name' => $template->provider_template_name,
            ]);

            return $message;
        });

        // Horizon picks the `whatsapp` queue up (see config/horizon.php).
        SendMessagesJob::dispatchFor($message);

        $template->markAsUsed();

        return $this->createdResponse([
            'message_id' => $message->message_id,
            'id' => $message->id,
            'status' => $message->status,
            'message_type' => $message->message_type,
            'template' => ['id' => $template->id, 'name' => $template->name, 'language' => $template->language],
            'recipient_number' => $recipient,
            'queued_at' => $message->created_at?->toIso8601String(),
        ], 'WhatsApp message queued for delivery');
    }

    /**
     * @OA\Post(
     *     path="/api/v1/app/messages/telegram",
     *     tags={"Application API"},
     *     security={{"bearerAuth":{}}},
     *     summary="Queue a Telegram message",
     *     description="Sends one of the application's active Telegram templates through its bot. The recipient must have started the bot: give external_ref (your reference, set by the invitation link they opened) or chat_id. A template whose file is given with each message (media_source `url`) needs media_url: a public link Telegram downloads itself (photo up to 5 MB, video or document up to 20 MB; by link, a document must be a PDF, ZIP or GIF). The message is `queued`, then `sent` (with Telegram's message id) or `failed` with the reason (blocked bot, unknown chat, link Telegram could not fetch...). Telegram gives no delivery or read receipt.",
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         @OA\Property(property="template_id", type="integer", description="Or template_name"),
     *         @OA\Property(property="template_name", type="string"),
     *         @OA\Property(property="external_ref", type="string", example="citizen-4521"),
     *         @OA\Property(property="chat_id", type="integer", description="Instead of external_ref"),
     *         @OA\Property(property="variables", type="object", example={"name": "Awa", "date": "12 octobre"}),
     *         @OA\Property(property="media_url", type="string", example="https://files.example.com/invoices/F-2026-001.pdf", description="Only for templates whose file is given with each message"),
     *         @OA\Property(property="campaign_id", type="string")
     *     )),
     *     @OA\Response(response=201, description="Message queued"),
     *     @OA\Response(response=404, description="Nobody subscribed with this reference or chat"),
     *     @OA\Response(response=422, description="Validation error, unknown or inactive template, missing variables, too long"),
     *     @OA\Response(response=503, description="No working Telegram bot for this application")
     * )
     */
    public function sendTelegram(Request $request): JsonResponse
    {
        $business = $this->application($request);

        $validator = Validator::make($request->all(), [
            'template_id' => 'required_without:template_name|nullable|integer',
            'template_name' => 'required_without:template_id|nullable|string|max:255',
            'external_ref' => 'required_without:chat_id|nullable|string|max:255',
            'chat_id' => 'required_without:external_ref|nullable|integer',
            'variables' => 'nullable|array',
            'media_url' => 'nullable|string|max:2048|url:http,https',
            'campaign_id' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $template = TelegramTemplate::where('business_id', $business->getKey())
            ->when($request->filled('template_id'), fn ($q) => $q->whereKey($request->input('template_id')))
            ->when(! $request->filled('template_id'), fn ($q) => $q->where('name', $request->input('template_name')))
            ->first();

        if (! $template) {
            return $this->errorResponse('Unknown Telegram template for this application.', [
                'template' => ['This template does not belong to your application.'],
            ], 422);
        }

        if (! $template->isReady()) {
            return $this->errorResponse('This Telegram template is not active.', [
                'template' => ['Activate the template before sending with it.'],
            ], 422);
        }

        if ($template->media_source === 'url' && ! $request->filled('media_url')) {
            return $this->errorResponse('This template sends a file given with each message: provide media_url.', [
                'media_url' => ["A public link to the {$template->media_type} Telegram downloads."],
            ], 422);
        }

        if ($template->media_source !== 'url' && $request->filled('media_url')) {
            return $this->errorResponse('This template takes no file link.', [
                'media_url' => ['Only templates whose file is given with each message take media_url.'],
            ], 422);
        }

        if ($template->isMissingItsFile()) {
            return $this->errorResponse('The file of this template is missing: attach it again in the console.', [
                'template' => ['File missing.'],
            ], 422);
        }

        $settings = TelegramSetting::where('business_id', $business->getKey())->first();

        if (! $settings || $settings->test_status !== 'success') {
            return $this->errorResponse('Telegram is not configured for this application.', null, 503);
        }

        $subscriber = TelegramSubscriber::where('business_id', $business->getKey())
            ->when($request->filled('external_ref'), fn ($q) => $q->where('external_ref', $request->input('external_ref')))
            ->when(! $request->filled('external_ref'), fn ($q) => $q->where('chat_id', $request->input('chat_id')))
            ->first();

        if (! $subscriber) {
            return $this->errorResponse('Nobody started the bot with this reference yet: send them an invitation link first.', null, 404);
        }

        if ($subscriber->status !== 'active') {
            return $this->errorResponse('This person blocked the bot or unsubscribed.', null, 422);
        }

        $variables = (array) $request->input('variables', []);

        if ($missing = $this->telegram->missing($template, $variables)) {
            return $this->errorResponse('Missing template variables: ' . implode(', ', $missing), [
                'variables' => ['Provide a value for: ' . implode(', ', $missing)],
            ], 422);
        }

        $composed = $this->telegram->compose($template, $variables);

        if ($this->telegram->visibleLength($composed['text'], $composed['parse_mode']) > TelegramComposer::maxLength($template->media_type)) {
            return $this->errorResponse($template->media_type
                ? 'The caption exceeds the 1024 characters Telegram allows with a file.'
                : 'The message exceeds the 4096 characters Telegram allows.', [
                    'variables' => ['Shorten the values.'],
                ], 422);
        }

        $message = DB::transaction(function () use ($business, $template, $subscriber, $request, $variables, $composed) {
            $message = Message::create([
                'business_id' => $business->getKey(),
                'message_id' => (string) Str::uuid(),
                'message_type' => 'telegram',
                'status' => 'queued',
                'campaign_id' => $request->input('campaign_id'),
            ]);

            TelegramMessage::create([
                'message_id' => $message->id,
                'telegram_template_id' => $template->id,
                'telegram_subscriber_id' => $subscriber->id,
                'chat_id' => $subscriber->chat_id,
                'recipient_label' => $subscriber->displayName(),
                'external_ref' => $subscriber->external_ref,
                'text' => $composed['text'],
                'parse_mode' => $composed['parse_mode'],
                'reply_markup' => $composed['reply_markup'],
                'media_type' => $composed['media_type'],
                'media_source' => $composed['media_source'],
                'media_url' => $composed['media_source'] === 'url' ? $request->input('media_url') : null,
                'disable_link_preview' => $composed['disable_link_preview'],
                'template_variables' => $variables,
            ]);

            return $message;
        });

        // Horizon picks the `telegram` queue up (see config/horizon.php).
        SendMessagesJob::dispatchFor($message);

        $template->markAsUsed();

        return $this->createdResponse([
            'message_id' => $message->message_id,
            'id' => $message->id,
            'status' => $message->status,
            'message_type' => $message->message_type,
            'template' => ['id' => $template->id, 'name' => $template->name],
            'recipient' => ['chat_id' => $subscriber->chat_id, 'external_ref' => $subscriber->external_ref],
            'media' => $template->media_type ? ['type' => $template->media_type, 'source' => $template->media_source] : null,
            'queued_at' => $message->created_at?->toIso8601String(),
        ], 'Telegram message queued for delivery');
    }

    /**
     * @OA\Post(
     *     path="/api/v1/app/telegram/invitations",
     *     tags={"Application API"},
     *     security={{"bearerAuth":{}}},
     *     summary="Invitation link for one of your users",
     *     description="Send the returned link to the person (email, SMS, your site). Once they press Start in the bot, messages can be sent to them with external_ref. A link works once and expires (7 days by default).",
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"external_ref"},
     *         @OA\Property(property="external_ref", type="string", example="citizen-4521"),
     *         @OA\Property(property="label", type="string"),
     *         @OA\Property(property="expires_in_hours", type="integer", example=168)
     *     )),
     *     @OA\Response(response=201, description="token, link, expires_at"),
     *     @OA\Response(response=503, description="No working Telegram bot for this application")
     * )
     */
    public function telegramInvitation(Request $request): JsonResponse
    {
        $business = $this->application($request);

        $validator = Validator::make($request->all(), array_merge(TelegramSettingController::invitationRules(), [
            'external_ref' => 'required|string|max:255',
        ]));

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $settings = TelegramSetting::where('business_id', $business->getKey())->first();

        if (! $settings?->bot_username || $settings->test_status !== 'success') {
            return $this->errorResponse('Telegram is not configured for this application.', null, 503);
        }

        $invitation = TelegramSettingController::createInvitation($business->getKey(), $request);

        return $this->createdResponse(TelegramSettingController::invitationPayload($invitation, $settings), 'Invitation link created');
    }

    /**
     * @OA\Get(
     *     path="/api/v1/app/telegram/subscribers/{externalRef}",
     *     tags={"Application API"},
     *     security={{"bearerAuth":{}}},
     *     summary="Did this user start the bot?",
     *     @OA\Parameter(name="externalRef", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="subscribed, status, subscribed_at"),
     *     @OA\Response(response=404, description="Not subscribed")
     * )
     */
    public function telegramSubscriber(Request $request, string $externalRef): JsonResponse
    {
        $business = $this->application($request);

        $subscriber = TelegramSubscriber::where('business_id', $business->getKey())
            ->where('external_ref', $externalRef)
            ->first();

        if (! $subscriber) {
            return $this->notFoundResponse('Subscriber');
        }

        return $this->successResponse([
            'subscribed' => $subscriber->status === 'active',
            'status' => $subscriber->status,
            'chat_id' => $subscriber->chat_id,
            'subscribed_at' => $subscriber->subscribed_at?->toIso8601String(),
            'blocked_at' => $subscriber->blocked_at?->toIso8601String(),
        ]);
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

        $message = Message::with(['emailMessage', 'whatsappMessage', 'telegramMessage'])
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
            'recipient' => $message->emailMessage?->recipient_email
                ?? $message->whatsappMessage?->recipient_number
                ?? $message->telegramMessage?->recipient_label,
            'subject' => $message->emailMessage?->subject,
            'whatsapp' => $message->whatsappMessage ? [
                'template' => $message->whatsappMessage->provider_template_name,
                'content' => $message->whatsappMessage->content,
                'provider_status' => $message->whatsappMessage->provider_status,
                'whatsapp_message_id' => $message->whatsappMessage->provider_message_id,
            ] : null,
            'telegram' => $message->telegramMessage ? [
                'chat_id' => $message->telegramMessage->chat_id,
                'external_ref' => $message->telegramMessage->external_ref,
                'telegram_message_id' => $message->telegramMessage->provider_message_id,
                'media' => $message->telegramMessage->media_type ? [
                    'type' => $message->telegramMessage->media_type,
                    'source' => $message->telegramMessage->media_source,
                    'url' => $message->telegramMessage->media_url,
                ] : null,
            ] : null,
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

    /**
     * @OA\Get(
     *     path="/api/v1/app/templates/whatsapp",
     *     tags={"Application API"},
     *     security={{"bearerAuth":{}}},
     *     summary="WhatsApp templates this application can send",
     *     description="Approved and active templates, with the variable numbers each one expects.",
     *     @OA\Response(response=200, description="Sendable WhatsApp templates")
     * )
     */
    public function whatsappTemplates(Request $request): JsonResponse
    {
        $business = $this->application($request);

        $templates = WhatsappTemplate::where('business_id', $business->getKey())
            ->where('is_active', true)
            ->where('status', 'approved')
            ->whereNotNull('provider_template_name')
            ->orderBy('name')
            ->get()
            ->map(fn (WhatsappTemplate $template) => [
                'id' => $template->id,
                'name' => $template->name,
                'display_name' => $template->display_name,
                'language' => $template->language,
                'category' => $template->category,
                'variables' => WhatsappVariables::expected($template),
                'body' => $template->body,
                'header_format' => $template->header['format'] ?? null,
            ])
            ->values();

        return $this->successResponse($templates);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/app/templates/telegram",
     *     tags={"Application API"},
     *     security={{"bearerAuth":{}}},
     *     summary="Telegram templates this application can send",
     *     description="With their variables and attachment: media_type (photo, video, document or null) and needs_media_url, true when media_url must go with each message.",
     *     @OA\Response(response=200, description="Active templates with their variables")
     * )
     */
    public function telegramTemplates(Request $request): JsonResponse
    {
        $business = $this->application($request);

        return $this->successResponse(TelegramTemplate::where('business_id', $business->getKey())
            ->where('is_active', true)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'category', 'body', 'parse_mode', 'variables', 'media_type', 'media_source', 'media_path'])
            // Whether media_url goes with each message.
            ->each(fn (TelegramTemplate $template) => $template->setAttribute('needs_media_url', $template->media_source === 'url')));
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
