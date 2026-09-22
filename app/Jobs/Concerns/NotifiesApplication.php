<?php

namespace App\Jobs\Concerns;

use App\Jobs\SendWebhookNotification;
use App\Models\Message;
use Illuminate\Support\Facades\Log;

/**
 * Hand a message event to the webhook queue without ever letting a webhook
 * problem affect the delivery itself.
 */
trait NotifiesApplication
{
    protected function queueWebhook(?Message $message, string $event): void
    {
        if (! $message) {
            return;
        }

        try {
            SendWebhookNotification::notify($message, $event);
        } catch (\Throwable $e) {
            Log::error(class_basename(static::class) . ': could not queue the webhook notification', [
                'message_id' => $message->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            $message->forceFill([
                'webhook_status' => 'failed',
                'webhook_error' => 'Could not be queued: ' . $e->getMessage(),
                'webhook_last_attempt_at' => now(),
            ])->save();
        }
    }
}
