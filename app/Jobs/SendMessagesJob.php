<?php

namespace App\Jobs;

use App\Mail\OutboundEmail;
use App\Models\Message;
use App\Models\SmtpSetting;
use App\Jobs\SendWebhookNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Delivers a queued message.
 *
 * Only the email channel is implemented; SMS and WhatsApp still have to be
 * wired to their providers and are failed explicitly rather than silently
 * reported as sent.
 */
class SendMessagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = 60;

    public $maxExceptions = 3;

    public function __construct(protected int $messageId)
    {
        // Horizon watches this queue (config/horizon.php).
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        $message = Message::with(['emailMessage', 'business'])->find($this->messageId);

        if (! $message) {
            Log::warning('SendMessagesJob: message not found', ['message_id' => $this->messageId]);

            return;
        }

        // A message cancelled while it waited in the queue must not go out.
        if ($message->status === 'cancelled') {
            Log::info('SendMessagesJob: message cancelled before sending', ['message_id' => $message->id]);

            return;
        }

        $message->update(['status' => 'sending']);

        $deliveryError = null;

        try {
            match ($message->message_type) {
                'email' => $this->sendEmail($message),
                default => throw new \RuntimeException(
                    "The {$message->message_type} channel is not implemented yet."
                ),
            };

            $message->update(['status' => 'sent', 'sent_at' => now()]);

            Log::info('SendMessagesJob: message sent', [
                'message_id' => $message->id,
                'message_type' => $message->message_type,
            ]);
        } catch (\Throwable $e) {
            Log::error('SendMessagesJob: sending failed', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            $message->update([
                'status' => 'failed',
                // Kept verbatim so the reason is visible from the dashboard and
                // in the webhook payload, without digging through the logs.
                'error_message' => $e->getMessage(),
                'failed_at' => now(),
            ]);
            $message->increment('retry_count');

            $deliveryError = $e;
        }

        // Notifying the application happens outside the try/catch on purpose.
        // The webhook is a separate job on a separate queue, and whether the
        // customer's endpoint answers says nothing about whether the email was
        // delivered: a webhook problem must never flip a sent message to failed,
        // nor make this job retry and send the email twice.
        $this->queueWebhook(
            $message->fresh(),
            $deliveryError ? SendWebhookNotification::EVENT_FAILED : SendWebhookNotification::EVENT_SENT
        );

        if ($deliveryError) {
            // Re-thrown so the queue applies the retry/backoff policy.
            throw $deliveryError;
        }
    }

    /**
     * Send through the SMTP configuration of the message's own application.
     */
    private function sendEmail(Message $message): void
    {
        $emailMessage = $message->emailMessage;

        if (! $emailMessage) {
            throw new \RuntimeException('The message has no email payload.');
        }

        $smtp = $this->resolveSmtpSetting($message);

        if (! $smtp) {
            throw new \RuntimeException('No active SMTP configuration for this application.');
        }

        // Build a mailer for this application instead of mutating the shared
        // config: two jobs may run concurrently for different applications.
        Config::set('mail.mailers.' . $this->mailerName($smtp), [
            'transport' => 'smtp',
            'host' => $smtp->host,
            'port' => $smtp->port,
            'encryption' => $smtp->encryption === 'none' ? null : $smtp->encryption,
            'username' => $smtp->username,
            'password' => $smtp->password,
            'timeout' => $smtp->timeout ?? 30,
            'verify_peer' => $smtp->verify_peer ?? true,
        ]);

        Mail::mailer($this->mailerName($smtp))
            ->to($emailMessage->recipient_email, $emailMessage->recipient_name)
            ->send(new OutboundEmail($emailMessage, $smtp));

        $smtp->forceFill([
            'messages_sent' => (int) $smtp->messages_sent + 1,
            'last_used_at' => now(),
        ])->save();
    }

    /**
     * Default active configuration of the application, else any active one.
     */
    private function resolveSmtpSetting(Message $message): ?SmtpSetting
    {
        return SmtpSetting::where('business_id', $message->business_id)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->first();
    }

    private function mailerName(SmtpSetting $smtp): string
    {
        return 'smtp_business_' . $smtp->business_id . '_' . $smtp->id;
    }

    /**
     * Hand the notification to its own queue, absorbing any dispatch problem
     * (a queue backend being momentarily unavailable, for instance).
     */
    private function queueWebhook(?Message $message, string $event): void
    {
        if (! $message) {
            return;
        }

        try {
            SendWebhookNotification::notify($message, $event);
        } catch (\Throwable $e) {
            Log::error('SendMessagesJob: could not queue the webhook notification', [
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

    public function failed(\Throwable $exception): void
    {
        Log::error('SendMessagesJob: job failed permanently', [
            'message_id' => $this->messageId,
            'error' => $exception->getMessage(),
        ]);

        Message::where('id', $this->messageId)->update([
            'status' => 'failed',
            'error_message' => 'Delivery failed after all retries: ' . $exception->getMessage(),
            'failed_at' => now(),
        ]);

        $this->queueWebhook(
            Message::with('business')->find($this->messageId),
            SendWebhookNotification::EVENT_FAILED
        );
    }
}
