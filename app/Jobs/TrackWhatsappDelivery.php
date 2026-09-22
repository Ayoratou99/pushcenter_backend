<?php

namespace App\Jobs;

use App\Jobs\Concerns\NotifiesApplication;
use App\Models\Message;
use App\Models\WhatsAppMessage;
use App\Models\WhatsappSetting;
use App\Services\AyosPush\AyosPushClient;
use App\Services\AyosPush\AyosPushException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Reads the outcome of a WhatsApp message AyosPush accepted (202 + request
 * id) on GET /v1/messages/{requestId}/status, and closes the message: `sent`
 * once AyosPush handed it to Meta, `failed` with AyosPush's reason otherwise.
 *
 * AyosPush does not report Meta's delivered/read receipts through its API, so
 * `sent` is the last status a WhatsApp message reaches here.
 */
class TrackWhatsappDelivery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, NotifiesApplication, Queueable, SerializesModels;

    /**
     * Seconds before each check. AyosPush usually sends within seconds; the
     * last check comes a little under two hours after the first.
     */
    public const DELAYS = [10, 30, 90, 300, 900, 1800, 3600];

    public $tries = 1;

    public $timeout = 60;

    public function __construct(public int $messageId, public int $check = 1)
    {
        $this->onQueue('whatsapp');
    }

    public static function schedule(int $messageId, int $check = 1, ?int $delay = null): void
    {
        $delay ??= self::DELAYS[min($check, count(self::DELAYS)) - 1];

        static::dispatch($messageId, $check)->delay(now()->addSeconds($delay));
    }

    public function handle(): void
    {
        $message = Message::with(['whatsappMessage', 'business'])->find($this->messageId);
        $whatsapp = $message?->whatsappMessage;

        // Closed meanwhile (cancelled, retried, answered by an earlier check).
        if (! $message || $message->status !== 'sending' || ! $whatsapp?->provider_request_id) {
            return;
        }

        $settings = WhatsappSetting::where('business_id', $message->business_id)->first();

        if (! $settings) {
            $this->close($message, 'WhatsApp settings were removed before AyosPush confirmed the message.');

            return;
        }

        try {
            $outcome = AyosPushClient::for($settings)->messageStatus((int) $whatsapp->provider_request_id);
        } catch (AyosPushException $e) {
            if ($e->status === 404) {
                $this->close($message, 'AyosPush no longer knows this message (request ' . $whatsapp->provider_request_id . ').');
            } elseif ($e->isRateLimit()) {
                // Same check, once AyosPush lets us in again.
                static::schedule($message->id, $this->check, max(5, (int) $e->retryAfter));
            } else {
                $this->later($message, $e->getMessage());
            }

            return;
        }

        $state = strtolower((string) ($outcome['status'] ?? ''));

        $whatsapp->forceFill([
            'provider_status' => $state !== '' ? $state : null,
            'provider_checked_at' => now(),
            'status_checks' => $whatsapp->status_checks + 1,
        ])->save();

        match ($state) {
            'success' => $this->sent($message, $whatsapp, $outcome),
            'failed' => $this->close(
                $message,
                'WhatsApp delivery failed: ' . ($outcome['error_message'] ?? 'AyosPush gave no reason.')
            ),
            // pending / queued: AyosPush has not sent it yet.
            default => $this->later($message, null),
        };
    }

    /**
     * @param  array<string, mixed>  $outcome
     */
    private function sent(Message $message, WhatsAppMessage $whatsapp, array $outcome): void
    {
        $body = $outcome['response_body'] ?? [];
        $body = is_string($body) ? (json_decode($body, true) ?: []) : (array) $body;

        $whatsapp->forceFill(['provider_message_id' => $body['message_id'] ?? null])->save();

        $message->update(['status' => 'sent', 'sent_at' => now(), 'error_message' => null]);

        Log::info('TrackWhatsappDelivery: sent', [
            'message_id' => $message->id,
            'whatsapp_message_id' => $body['message_id'] ?? null,
        ]);

        $this->queueWebhook($message->fresh(), SendWebhookNotification::EVENT_SENT);
    }

    private function later(Message $message, ?string $problem): void
    {
        if ($this->check >= count(self::DELAYS)) {
            $minutes = (int) round(array_sum(self::DELAYS) / 60);

            $this->close(
                $message,
                "No confirmation from AyosPush after {$minutes} minutes"
                . ($problem ? ": {$problem}" : '.')
                . ' The message may still go out: check before retrying it.'
            );

            return;
        }

        if ($problem) {
            $message->update(['error_message' => "Waiting for AyosPush: {$problem}"]);
        }

        static::schedule($message->id, $this->check + 1);
    }

    private function close(Message $message, string $reason): void
    {
        Log::warning('TrackWhatsappDelivery: failed', ['message_id' => $message->id, 'reason' => $reason]);

        $message->update(['status' => 'failed', 'error_message' => $reason, 'failed_at' => now()]);

        $this->queueWebhook($message->fresh(), SendWebhookNotification::EVENT_FAILED);
    }
}
