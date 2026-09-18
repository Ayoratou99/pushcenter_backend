<?php

namespace App\Jobs;

use App\Models\Business;
use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Notifies an application of one of its messages changing state.
 *
 * Runs on its own queue so a slow or unreachable customer endpoint never holds
 * up actual message delivery. The payload is signed with the application's
 * webhook secret (HMAC-SHA256 over the exact body) so the receiver can prove it
 * comes from us and has not been tampered with.
 */
class SendWebhookNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const EVENT_SENT = 'message.sent';

    public const EVENT_FAILED = 'message.failed';

    /** Spread retries out: a customer endpoint may be down for a while. */
    public $tries = 5;

    public array $backoff = [10, 60, 300, 900];

    public function __construct(
        protected int $messageId,
        protected string $event,
    ) {
        $this->onQueue('webhooks');
    }

    /**
     * Queue a notification when the application asked for this event.
     */
    public static function notify(Message $message, string $event): void
    {
        $business = $message->business ?? Business::find($message->business_id);

        if (! $business?->wantsWebhook($event)) {
            return;
        }

        $message->forceFill([
            'webhook_status' => 'pending',
            'webhook_error' => null,
        ])->save();

        self::dispatch($message->id, $event);
    }

    public function handle(): void
    {
        $message = Message::with(['business', 'emailMessage'])->find($this->messageId);

        if (! $message || ! $message->business?->wantsWebhook($this->event)) {
            return;
        }

        $business = $message->business;
        $payload = $this->payload($message);
        // Sign the exact bytes that travel, not a re-encoding of them.
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'AninfPush-Webhook/1.0',
            'X-AninfPush-Event' => $this->event,
            'X-AninfPush-Delivery' => $message->message_id,
        ];

        if ($business->webhook_secret) {
            $headers['X-AninfPush-Signature'] = 'sha256=' . hash_hmac('sha256', $body, $business->webhook_secret);
        }

        $message->forceFill([
            'webhook_attempts' => (int) $message->webhook_attempts + 1,
            'webhook_last_attempt_at' => now(),
        ])->save();

        try {
            $response = Http::withHeaders($headers)
                ->timeout(10)
                ->connectTimeout(5)
                ->withBody($body, 'application/json')
                ->post($business->webhook_url);
        } catch (\Throwable $e) {
            // Unreachable host, DNS failure, TLS problem...
            $this->recordFailure($message, $e->getMessage());

            throw $e;
        }

        if ($response->failed()) {
            $reason = "Endpoint answered HTTP {$response->status()}: "
                . Str::limit((string) $response->body(), 500);

            $this->recordFailure($message, $reason);

            // Thrown so the queue retries with the backoff above.
            throw new \RuntimeException($reason);
        }

        $message->forceFill([
            'webhook_status' => 'delivered',
            'webhook_error' => null,
        ])->save();

        Log::info('Webhook delivered', [
            'business_id' => $business->id,
            'message_id' => $message->message_id,
            'event' => $this->event,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Message $message): array
    {
        return [
            'event' => $this->event,
            'occurred_at' => now()->toIso8601String(),
            'data' => [
                'message_id' => $message->message_id,
                'id' => $message->id,
                'message_type' => $message->message_type,
                'status' => $message->status,
                'recipient' => $message->emailMessage?->recipient_email,
                'subject' => $message->emailMessage?->subject,
                'campaign_id' => $message->campaign_id,
                // The reason a message failed, so the application can act on it
                // without polling the API.
                'error_message' => $message->error_message,
                'retry_count' => $message->retry_count,
                'sent_at' => $message->sent_at?->toIso8601String(),
                'failed_at' => $message->failed_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * Record why the notification did not get through, on the message itself,
     * so it is visible from the dashboard next to the delivery status.
     */
    private function recordFailure(Message $message, string $reason): void
    {
        Log::warning('Webhook not delivered', [
            'business_id' => $message->business_id,
            'message_id' => $message->message_id,
            'event' => $this->event,
            'reason' => $reason,
        ]);

        $message->forceFill([
            'webhook_status' => 'failed',
            'webhook_error' => $reason,
        ])->save();
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Webhook permanently undeliverable', [
            'message_id' => $this->messageId,
            'event' => $this->event,
            'error' => $exception->getMessage(),
        ]);

        $message = Message::find($this->messageId);

        if ($message) {
            $message->forceFill([
                'webhook_status' => 'failed',
                'webhook_error' => 'Gave up after ' . $this->attempts() . ' attempt(s): '
                    . $exception->getMessage(),
                'webhook_last_attempt_at' => now(),
            ])->save();
        }
    }
}
