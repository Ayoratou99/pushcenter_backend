<?php

namespace App\Http\Controllers\Api;

use App\Models\Business;
use App\Models\TelegramInvitation;
use App\Models\TelegramSetting;
use App\Models\TelegramSubscriber;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Services\Telegram\TelegramUpdates;
use App\Support\QueryFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * @OA\Tag(name="Telegram", description="The Telegram bot of an application, its subscribers and invitation links")
 */
class TelegramSettingController extends BaseController
{
    /**
     * @OA\Get(
     *     path="/api/v1/businesses/{businessId}/telegram-settings",
     *     tags={"Telegram"},
     *     security={{"bearerAuth":{}}},
     *     summary="Telegram bot of an application",
     *     description="The token is never returned, only its last four characters. `data` is absent when no bot is configured.",
     *     @OA\Parameter(name="businessId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Bot settings")
     * )
     */
    public function show(int $businessId): JsonResponse
    {
        if ($deny = $this->guard($businessId)) {
            return $deny;
        }

        $setting = TelegramSetting::where('business_id', $businessId)->first();

        return $setting
            ? $this->successResponse($setting->toConsoleArray())
            : $this->successResponse(null, 'Telegram is not configured for this application');
    }

    /**
     * @OA\Put(
     *     path="/api/v1/businesses/{businessId}/telegram-settings",
     *     tags={"Telegram"},
     *     security={{"bearerAuth":{}}},
     *     summary="Save the bot token of an application",
     *     description="The token comes from @BotFather. Leave bot_token empty to keep the stored one. A token of another bot marks the current subscribers as blocked: they must start the new bot.",
     *     @OA\Parameter(name="businessId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         @OA\Property(property="bot_token", type="string", example="123456789:AAH..."),
     *         @OA\Property(property="welcome_message", type="string", description="Sent when someone starts the bot")
     *     )),
     *     @OA\Response(response=200, description="Saved"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function update(Request $request, int $businessId): JsonResponse
    {
        if ($deny = $this->guard($businessId)) {
            return $deny;
        }

        $setting = TelegramSetting::where('business_id', $businessId)->first();

        $validator = Validator::make($request->all(), [
            // <bot id>:<35 characters>, as @BotFather hands it out.
            'bot_token' => [$setting ? 'nullable' : 'required', 'string', 'max:100', 'regex:/^\d{5,}:[A-Za-z0-9_-]{30,}$/'],
            'welcome_message' => 'nullable|string|max:1000',
        ], [
            'bot_token.regex' => 'This does not look like a bot token: copy the whole line @BotFather gave you (123456789:AA…).',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $setting ??= new TelegramSetting(['business_id' => $businessId]);
        $token = trim((string) $request->input('bot_token', ''));
        $otherBot = false;

        if ($token !== '' && $token !== $setting->bot_token) {
            $setting->forceFill(['bot_token' => $token, 'test_status' => 'not_tested', 'test_error' => null]);

            // A token starts with the bot id: a token revoked in @BotFather
            // keeps its bot, another id is another bot.
            if (Str::before($token, ':') !== Str::before((string) $setting->getOriginal('bot_token'), ':')) {
                $otherBot = $setting->exists;
                $setting->forceFill(['bot_id' => null, 'bot_username' => null, 'bot_name' => null, 'update_offset' => 0]);
            }
        }

        if ($request->exists('welcome_message')) {
            $setting->welcome_message = $request->input('welcome_message');
        }

        $setting->save();

        if ($otherBot) {
            // Nobody started the new bot yet, and a bot cannot write first.
            // Their reference comes back when they start it.
            TelegramSubscriber::where('business_id', $businessId)
                ->where('status', 'active')
                ->update(['status' => 'blocked', 'blocked_at' => now()]);
        }

        return $this->successResponse($setting->toConsoleArray(), 'Telegram settings saved');
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/businesses/{businessId}/telegram-settings",
     *     tags={"Telegram"},
     *     security={{"bearerAuth":{}}},
     *     summary="Remove the bot of an application",
     *     @OA\Parameter(name="businessId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Removed"),
     *     @OA\Response(response=404, description="Not configured")
     * )
     */
    public function destroy(int $businessId): JsonResponse
    {
        if ($deny = $this->guard($businessId)) {
            return $deny;
        }

        $setting = TelegramSetting::where('business_id', $businessId)->first();

        if (! $setting) {
            return $this->notFoundResponse('Telegram settings');
        }

        $setting->delete();

        return $this->deletedResponse('Telegram bot removed');
    }

    /**
     * @OA\Post(
     *     path="/api/v1/businesses/{businessId}/telegram-settings/test",
     *     tags={"Telegram"},
     *     security={{"bearerAuth":{}}},
     *     summary="Check the bot token",
     *     description="Calls getMe to read the bot's name and username, and checks no webhook is set (AninfPush reads updates by polling).",
     *     @OA\Parameter(name="businessId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Connected"),
     *     @OA\Response(response=422, description="Token refused, or webhook set on the bot"),
     *     @OA\Response(response=502, description="Telegram unreachable")
     * )
     */
    public function test(int $businessId): JsonResponse
    {
        if ($deny = $this->guard($businessId)) {
            return $deny;
        }

        $setting = TelegramSetting::where('business_id', $businessId)->first();

        if (! $setting) {
            return $this->notFoundResponse('Telegram settings');
        }

        $client = TelegramClient::for($setting);

        try {
            $bot = $client->getMe();
            $webhook = $client->getWebhookInfo();
        } catch (TelegramException $e) {
            $setting->forceFill(['test_status' => 'failed', 'test_error' => $e->getMessage(), 'last_tested_at' => now()])->save();

            return $this->errorResponse($e->getMessage(), ['settings' => $setting->toConsoleArray()], $e->consoleStatus());
        }

        $setting->forceFill([
            'bot_id' => $bot['id'] ?? null,
            'bot_username' => $bot['username'] ?? null,
            'bot_name' => $bot['first_name'] ?? null,
            'last_tested_at' => now(),
        ]);

        if (! empty($webhook['url'])) {
            $message = 'This bot sends its updates to a webhook (' . parse_url((string) $webhook['url'], PHP_URL_HOST)
                . '): AninfPush cannot read who starts it. Use a bot dedicated to AninfPush, or remove the webhook with deleteWebhook.';
            $setting->forceFill(['test_status' => 'failed', 'test_error' => $message])->save();

            return $this->errorResponse($message, ['settings' => $setting->toConsoleArray()], 422);
        }

        $setting->forceFill(['test_status' => 'success', 'test_error' => null])->save();

        return $this->successResponse($setting->toConsoleArray(), 'Connected to @' . $setting->bot_username);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/businesses/{businessId}/telegram-settings/poll",
     *     tags={"Telegram"},
     *     security={{"bearerAuth":{}}},
     *     summary="Read the bot's new subscriptions now",
     *     description="What `php artisan telegram:poll` does every minute: getUpdates, then records who started (or blocked) the bot.",
     *     @OA\Parameter(name="businessId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Counts: updates, subscribed, blocked")
     * )
     */
    public function poll(int $businessId, TelegramUpdates $updates): JsonResponse
    {
        if ($deny = $this->guard($businessId)) {
            return $deny;
        }

        $setting = TelegramSetting::with('business')->where('business_id', $businessId)->first();

        if (! $setting) {
            return $this->notFoundResponse('Telegram settings');
        }

        try {
            $counts = $updates->poll($setting);
        } catch (TelegramException $e) {
            return $this->errorResponse($e->getMessage(), null, $e->consoleStatus());
        }

        return $this->successResponse($counts, "{$counts['subscribed']} new subscription(s), {$counts['blocked']} unsubscription(s)");
    }

    /**
     * @OA\Get(
     *     path="/api/v1/businesses/{businessId}/telegram-subscribers",
     *     tags={"Telegram"},
     *     security={{"bearerAuth":{}}},
     *     summary="People who started the bot",
     *     @OA\Parameter(name="businessId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="search", in="query", description="Name, username or reference", @OA\Schema(type="string")),
     *     @OA\Parameter(name="status", in="query", @OA\Schema(type="string", enum={"active","blocked"})),
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=15)),
     *     @OA\Response(response=200, description="Paginated subscribers")
     * )
     */
    public function subscribers(Request $request, int $businessId): JsonResponse
    {
        if ($deny = $this->guard($businessId)) {
            return $deny;
        }

        $query = TelegramSubscriber::where('business_id', $businessId);
        QueryFilters::exact($query, $request, ['status', 'external_ref']);
        QueryFilters::search($query, $request->input('search'), ['first_name', 'last_name', 'username', 'external_ref']);
        $query->orderByDesc('subscribed_at');

        return $this->successResponse($query->paginate(QueryFilters::perPage($request)));
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/businesses/{businessId}/telegram-subscribers/{id}",
     *     tags={"Telegram"},
     *     security={{"bearerAuth":{}}},
     *     summary="Forget a subscriber",
     *     description="Nothing more is sent to this chat until the person starts the bot again.",
     *     @OA\Parameter(name="businessId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Forgotten")
     * )
     */
    public function destroySubscriber(int $businessId, int $id): JsonResponse
    {
        if ($deny = $this->guard($businessId)) {
            return $deny;
        }

        $subscriber = TelegramSubscriber::where('business_id', $businessId)->find($id);

        if (! $subscriber) {
            return $this->notFoundResponse('Subscriber');
        }

        $subscriber->delete();

        return $this->deletedResponse('Subscriber forgotten');
    }

    /**
     * @OA\Get(
     *     path="/api/v1/businesses/{businessId}/telegram-invitations",
     *     tags={"Telegram"},
     *     security={{"bearerAuth":{}}},
     *     summary="Invitation links created for an application",
     *     @OA\Parameter(name="businessId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Paginated invitations, newest first")
     * )
     */
    public function invitations(Request $request, int $businessId): JsonResponse
    {
        if ($deny = $this->guard($businessId)) {
            return $deny;
        }

        $setting = TelegramSetting::where('business_id', $businessId)->first();
        $page = TelegramInvitation::with('subscriber:id,first_name,last_name,username')
            ->where('business_id', $businessId)
            ->orderByDesc('id')
            ->paginate(QueryFilters::perPage($request));

        $page->getCollection()->each(fn (TelegramInvitation $invitation) => $invitation->setAttribute(
            'link',
            $setting?->botLink($invitation->token)
        ));

        return $this->successResponse($page);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/businesses/{businessId}/telegram-invitations",
     *     tags={"Telegram"},
     *     security={{"bearerAuth":{}}},
     *     summary="Create an invitation link",
     *     description="A t.me link to send to one person (by email, SMS, on your site...). Opening it and pressing Start subscribes their chat, tied to external_ref, your own reference for them. Without external_ref, the link is a generic one anybody can use.",
     *     @OA\Parameter(name="businessId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(@OA\JsonContent(
     *         @OA\Property(property="external_ref", type="string", example="citizen-4521"),
     *         @OA\Property(property="label", type="string", example="Awa Ndong"),
     *         @OA\Property(property="expires_in_hours", type="integer", example=168)
     *     )),
     *     @OA\Response(response=201, description="token, link, expires_at"),
     *     @OA\Response(response=422, description="The bot is not connected yet")
     * )
     */
    public function storeInvitation(Request $request, int $businessId): JsonResponse
    {
        if ($deny = $this->guard($businessId)) {
            return $deny;
        }

        $validator = Validator::make($request->all(), self::invitationRules());

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $setting = TelegramSetting::where('business_id', $businessId)->first();

        if (! $setting?->bot_username) {
            return $this->errorResponse('Connect the bot first: its username is needed to build the link.', null, 422);
        }

        $invitation = self::createInvitation($businessId, $request, $request->user()?->getKey());

        return $this->createdResponse(self::invitationPayload($invitation, $setting), 'Invitation link created');
    }

    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, string>
     */
    public static function invitationRules(): array
    {
        return [
            'external_ref' => 'nullable|string|max:255',
            'label' => 'nullable|string|max:255',
            'expires_in_hours' => 'nullable|integer|min:1|max:8760',
        ];
    }

    public static function createInvitation(int $businessId, Request $request, ?int $createdBy = null): TelegramInvitation
    {
        return TelegramInvitation::create([
            'business_id' => $businessId,
            // Telegram accepts A-Z, a-z, 0-9, _ and - in a start payload.
            'token' => Str::random(32),
            'external_ref' => $request->input('external_ref'),
            'label' => $request->input('label'),
            'expires_at' => now()->addHours((int) $request->input('expires_in_hours', 24 * 7)),
            'created_by' => $createdBy,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function invitationPayload(TelegramInvitation $invitation, TelegramSetting $setting): array
    {
        return [
            'id' => $invitation->id,
            'token' => $invitation->token,
            'link' => $setting->botLink($invitation->token),
            'external_ref' => $invitation->external_ref,
            'label' => $invitation->label,
            'expires_at' => $invitation->expires_at?->toIso8601String(),
        ];
    }

    private function guard(int $businessId): ?JsonResponse
    {
        if ($deny = $this->denyUnlessBusinessAccessible($businessId)) {
            return $deny;
        }

        return Business::whereKey($businessId)->exists() ? null : $this->notFoundResponse('Application');
    }
}
