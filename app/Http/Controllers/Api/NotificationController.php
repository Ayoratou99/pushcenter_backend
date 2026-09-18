<?php

namespace App\Http\Controllers\Api;

use App\Models\Message;
use App\Models\SmtpSetting;
use App\Support\QueryFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Operational notifications, derived from real state rather than stored rows:
 * a notification exists exactly as long as the problem it describes does.
 *
 * Everything is scoped to the applications the signed-in user may see.
 *
 * @OA\Tag(name="Notifications", description="Operational alerts")
 */
class NotificationController extends BaseController
{
    /** How far back an event stays worth showing. */
    private const WINDOW_DAYS = 7;

    private const LIMIT = 30;

    /**
     * @OA\Get(
     *     path="/api/v1/notifications",
     *     tags={"Notifications"},
     *     security={{"bearerAuth":{}}},
     *     summary="Recent operational alerts",
     *     description="Failed deliveries, undelivered webhooks and broken SMTP configurations, newest first.",
     *     @OA\Response(response=200, description="Notifications and unread count")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $since = now()->subDays(self::WINDOW_DAYS);
        $readAt = $request->user()->notifications_read_at;

        $notifications = collect([
            ...$this->failedMessages($request, $since),
            ...$this->failedWebhooks($request, $since),
            ...$this->brokenSmtpSettings($request, $since),
        ])
            ->sortByDesc('occurred_at')
            ->take(self::LIMIT)
            ->map(function (array $notification) use ($readAt) {
                // Timestamps have second granularity, so an alert raised in
                // the very second the panel was opened counts as read. It still
                // shows in the list, only the badge misses it.
                $notification['is_unread'] = $readAt === null
                    || $notification['occurred_at']->greaterThan($readAt);
                $notification['occurred_at'] = $notification['occurred_at']->toIso8601String();

                return $notification;
            })
            ->values();

        return $this->successResponse([
            'notifications' => $notifications,
            'unread_count' => $notifications->where('is_unread', true)->count(),
            'read_at' => $readAt?->toIso8601String(),
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/notifications/read",
     *     tags={"Notifications"},
     *     security={{"bearerAuth":{}}},
     *     summary="Mark every notification as read",
     *     @OA\Response(response=200, description="Marked as read")
     * )
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->forceFill(['notifications_read_at' => now()])->save();

        return $this->successResponse(
            ['read_at' => now()->toIso8601String()],
            'All notifications marked as read'
        );
    }

    /* ------------------------------------------------------------------ */

    /**
     * @return array<int, array<string, mixed>>
     */
    private function failedMessages(Request $request, $since): array
    {
        $query = Message::with(['business:id,name', 'emailMessage'])
            ->where('status', 'failed')
            ->where('failed_at', '>=', $since);

        QueryFilters::restrictToUserBusinesses($query, $request);

        return $query->latest('failed_at')->limit(self::LIMIT)->get()
            ->map(fn (Message $message) => [
                'id' => 'message-failed-' . $message->id,
                'type' => 'message.failed',
                'severity' => 'error',
                'title' => Str::ucfirst($message->message_type) . ' delivery failed',
                'description' => $this->describeFailure($message),
                'occurred_at' => $message->failed_at,
                'business' => $message->business?->only(['id', 'name']),
                // Where the dashboard should take the user.
                'link' => ['view' => 'messages', 'message_id' => $message->message_id],
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function failedWebhooks(Request $request, $since): array
    {
        $query = Message::with('business:id,name')
            ->where('webhook_status', 'failed')
            ->where('webhook_last_attempt_at', '>=', $since);

        QueryFilters::restrictToUserBusinesses($query, $request);

        return $query->latest('webhook_last_attempt_at')->limit(self::LIMIT)->get()
            ->map(fn (Message $message) => [
                'id' => 'webhook-failed-' . $message->id,
                'type' => 'webhook.failed',
                'severity' => 'warning',
                'title' => 'Webhook not delivered',
                'description' => Str::limit(
                    ($message->business?->name ? $message->business->name . ' — ' : '')
                        . ($message->webhook_error ?: 'The endpoint did not answer.'),
                    140
                ),
                'occurred_at' => $message->webhook_last_attempt_at,
                'business' => $message->business?->only(['id', 'name']),
                'link' => ['view' => 'messages', 'message_id' => $message->message_id],
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function brokenSmtpSettings(Request $request, $since): array
    {
        $query = SmtpSetting::with('business:id,name')
            ->where('test_status', 'failed')
            ->where('last_tested_at', '>=', $since);

        QueryFilters::restrictToUserBusinesses($query, $request);

        return $query->latest('last_tested_at')->limit(self::LIMIT)->get()
            ->map(fn (SmtpSetting $setting) => [
                'id' => 'smtp-failed-' . $setting->id,
                'type' => 'smtp.failed',
                'severity' => 'warning',
                'title' => 'SMTP configuration failing',
                'description' => Str::limit(
                    $setting->name . ' — ' . ($setting->test_error ?: 'The last test did not go through.'),
                    140
                ),
                'occurred_at' => $setting->last_tested_at,
                'business' => $setting->business?->only(['id', 'name']),
                'link' => ['view' => 'business', 'business_id' => $setting->business_id, 'tab' => 'email'],
            ])
            ->all();
    }

    private function describeFailure(Message $message): string
    {
        $recipient = $message->emailMessage?->recipient_email;

        return Str::limit(
            ($recipient ? $recipient . ' — ' : '')
                . ($message->error_message ?: 'No reason was recorded.'),
            140
        );
    }
}
