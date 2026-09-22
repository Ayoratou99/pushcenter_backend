<?php

namespace App\Jobs;

use App\Jobs\Concerns\NotifiesApplication;
use App\Mail\OutboundEmail;
use App\Models\Message;
use App\Models\SmtpSetting;
use App\Models\TelegramMessage;
use App\Models\TelegramSetting;
use App\Models\TelegramTemplate;
use App\Models\WhatsappSetting;
use App\Services\AyosPush\AyosPushClient;
use App\Services\AyosPush\AyosPushException;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Delivers a queued message.
 *
 * Email goes out through the application's own SMTP settings; WhatsApp through
 * AyosPush (POST /v1/messages/template), whose outcome is then followed by
 * TrackWhatsappDelivery; Telegram through the application's bot. SMS is not
 * wired to a provider yet and is failed explicitly rather than silently
 * reported as sent.
 */
class SendMessagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, NotifiesApplication, Queueable, SerializesModels;

    /**
     * Unhandled exceptions tolerated before the message is failed. Waiting for
     * AyosPush's rate limit (a release) does not count as one.
     */
    public $maxExceptions = 3;

    public $backoff = 60;

    public function __construct(protected int $messageId)
    {
        // Horizon watches this queue (config/horizon.php).
        $this->onQueue('emails');
    }

    /**
     * Queue watched for each channel (config/horizon.php).
     */
    public static function queueFor(string $messageType): string
    {
        return match ($messageType) {
            'whatsapp' => 'whatsapp',
            'telegram' => 'telegram',
            'sms' => 'sms',
            default => 'emails',
        };
    }

    public static function dispatchFor(Message $message): PendingDispatch
    {
        return static::dispatch($message->id)->onQueue(static::queueFor($message->message_type));
    }

    /**
     * Attempts (and rate-limit waits) stop two hours after queueing.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function handle(): void
    {
        $message = Message::with(['emailMessage', 'whatsappMessage', 'telegramMessage.subscriber', 'business'])->find($this->messageId);

        if (! $message) {
            Log::warning('SendMessagesJob: message not found', ['message_id' => $this->messageId]);

            return;
        }

        // A message cancelled while it waited in the queue must not go out.
        if ($message->status === 'cancelled') {
            Log::info('SendMessagesJob: message cancelled before sending', ['message_id' => $message->id]);

            return;
        }

        if ($message->message_type === 'whatsapp') {
            $this->sendWhatsapp($message);

            return;
        }

        if ($message->message_type === 'telegram') {
            $this->sendTelegram($message);

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
     * Hand the message to AyosPush with the application's key and sender.
     *
     * AyosPush answers 202 and sends from its own queue: the message stays
     * `sending` until TrackWhatsappDelivery reads the outcome.
     */
    private function sendWhatsapp(Message $message): void
    {
        $whatsapp = $message->whatsappMessage;

        if (! $whatsapp) {
            $this->failWithoutRetry($message, 'The message has no WhatsApp payload.');

            return;
        }

        // Accepted on a previous attempt: sending again would reach the
        // recipient twice. Only the follow-up is left to do.
        if ($whatsapp->provider_request_id) {
            $message->update(['status' => 'sending']);
            TrackWhatsappDelivery::schedule($message->id);

            return;
        }

        $settings = WhatsappSetting::where('business_id', $message->business_id)->first();
        $sender = $whatsapp->sender_phone_number_id ?: $settings?->default_phone_number_id;

        $problem = match (true) {
            ! $settings => 'WhatsApp is not configured for this application.',
            ! $sender => 'No WhatsApp sender number is selected for this application.',
            ! $whatsapp->provider_template_name => 'The template of this message is not on AyosPush.',
            default => null,
        };

        if ($problem) {
            $this->failWithoutRetry($message, $problem);

            return;
        }

        $message->update(['status' => 'sending']);

        try {
            $accepted = AyosPushClient::for($settings)->sendTemplate(
                $sender,
                $whatsapp->recipient_number,
                $whatsapp->provider_template_name,
                (array) ($whatsapp->template_variables ?? [])
            );
        } catch (AyosPushException $e) {
            $this->handleWhatsappRefusal($message, $e);

            return;
        }

        $whatsapp->forceFill([
            'provider_request_id' => $accepted['request_id'] ?? null,
            'provider_status' => $accepted['status'] ?? 'queued',
            'sender_phone_number_id' => $sender,
            'status_checks' => 0,
            'provider_checked_at' => null,
        ])->save();

        if (empty($accepted['request_id'])) {
            // Accepted, but nothing to follow: count it as handed over.
            $message->update(['status' => 'sent', 'sent_at' => now(), 'error_message' => null]);
            $this->queueWebhook($message->fresh(), SendWebhookNotification::EVENT_SENT);

            return;
        }

        $message->update(['status' => 'sending', 'error_message' => null]);

        TrackWhatsappDelivery::schedule($message->id);

        Log::info('SendMessagesJob: WhatsApp message accepted by AyosPush', [
            'message_id' => $message->id,
            'request_id' => $accepted['request_id'],
        ]);
    }

    private function handleWhatsappRefusal(Message $message, AyosPushException $e): void
    {
        if ($e->isRateLimit()) {
            // AyosPush's API limit (5/s, 1000/h per account): not a failure.
            $message->update([
                'status' => 'queued',
                'error_message' => 'Waiting for the AyosPush rate limit: ' . $e->getMessage(),
            ]);
            $this->release(max(5, (int) $e->retryAfter));

            return;
        }

        if ($e->mayHaveReachedAyosPush()) {
            // No answer, but AyosPush may have queued it: retrying could send
            // it twice. Leave the decision to a person.
            $this->failWithoutRetry(
                $message,
                $e->getMessage() . ' AyosPush may have received the message: check before retrying it.'
            );

            return;
        }

        if ($e->isTransient() && ! $e->isMessagingTierLimit()) {
            $message->update([
                'status' => 'queued',
                'error_message' => 'Retrying after a temporary AyosPush error: ' . $e->getMessage(),
            ]);

            // Counted against maxExceptions; failed() closes the message.
            throw $e;
        }

        // Refused for good: template not approved, invalid sender, quota,
        // messaging tier, wrong key...
        $this->failWithoutRetry($message, $e->getMessage());
    }

    /**
     * sendMessage through the application's bot. Telegram answers at once:
     * the message is `sent` (Telegram gives no delivery or read receipt).
     */
    private function sendTelegram(Message $message): void
    {
        $telegram = $message->telegramMessage;

        if (! $telegram) {
            $this->failWithoutRetry($message, 'The message has no Telegram payload.');

            return;
        }

        // Sent on a previous attempt: never twice.
        if ($telegram->provider_message_id) {
            $message->update(['status' => 'sent', 'sent_at' => $message->sent_at ?? now()]);

            return;
        }

        $settings = TelegramSetting::where('business_id', $message->business_id)->first();

        if (! $settings) {
            $this->failWithoutRetry($message, 'Telegram is not configured for this application.');

            return;
        }

        if ($telegram->subscriber?->status === 'blocked') {
            $this->failWithoutRetry($message, 'The recipient blocked the bot or unsubscribed.');

            return;
        }

        // The template's file is read when sending: it may have gone meanwhile.
        if ($telegram->media_source === 'file'
            && (! $telegram->template || $telegram->template->media_source !== 'file' || $telegram->template->isMissingItsFile())) {
            $this->failWithoutRetry($message, 'The file of the Telegram template is missing: attach it again in the console, then retry the message.');

            return;
        }

        $message->update(['status' => 'sending']);

        try {
            $client = TelegramClient::for($settings);
            $sent = $telegram->media_type
                ? $this->sendTelegramMedia($client, $telegram, $settings)
                : $client->sendMessage(
                    (int) $telegram->chat_id,
                    (string) $telegram->text,
                    $telegram->parse_mode,
                    $telegram->reply_markup,
                    (bool) $telegram->disable_link_preview
                );
        } catch (TelegramException $e) {
            if ($e->isRateLimit()) {
                $message->update(['status' => 'queued', 'error_message' => 'Waiting for the Telegram rate limit: ' . $e->getMessage()]);
                $this->release(max(1, (int) $e->retryAfter));

                return;
            }

            if ($e->isRecipientGone()) {
                $telegram->subscriber?->forceFill(['status' => 'blocked', 'blocked_at' => now()])->save();
                $this->failWithoutRetry($message, $e->getMessage() . ' The person blocked the bot, left, or deleted their account.');

                return;
            }

            if ($e->mayHaveReachedTelegram()) {
                $this->failWithoutRetry($message, $e->getMessage() . ' Telegram may have delivered it: check before retrying it.');

                return;
            }

            if ($e->isTransient()) {
                $message->update(['status' => 'queued', 'error_message' => 'Retrying after a temporary Telegram error: ' . $e->getMessage()]);

                throw $e;
            }

            if ($telegram->media_source === 'url' && $e->status === 400) {
                $this->failWithoutRetry($message, $e->getMessage() . ' Telegram could not use the file link: it must be public and lead straight to the file'
                    . ' (a photo up to 5 MB; a video or document up to 20 MB, a document by link being a PDF, ZIP or GIF).');

                return;
            }

            $this->failWithoutRetry($message, $e->getMessage());

            return;
        }

        $telegram->forceFill(['provider_message_id' => $sent['message_id'] ?? null])->save();
        $telegram->subscriber?->forceFill(['last_message_at' => now()])->save();

        $message->update(['status' => 'sent', 'sent_at' => now(), 'error_message' => null]);

        $this->queueWebhook($message->fresh(), SendWebhookNotification::EVENT_SENT);
    }

    /**
     * A photo, video or document with the text as its caption: from the link
     * given with the message, or the template's file. That file is uploaded
     * once per bot, then sent again by the id Telegram gave it.
     *
     * @return array<string, mixed>  the sent Message
     */
    private function sendTelegramMedia(TelegramClient $client, TelegramMessage $telegram, TelegramSetting $settings): array
    {
        $send = fn (string $type, string|array $media) => $client->sendMedia(
            $type,
            (int) $telegram->chat_id,
            $media,
            (string) $telegram->text,
            $telegram->parse_mode,
            $telegram->reply_markup
        );

        if ($telegram->media_source === 'url') {
            return $send((string) $telegram->media_type, (string) $telegram->media_url);
        }

        /** @var TelegramTemplate $template */
        $template = $telegram->template;
        // The kind of the file as it is now: a photo cannot go as a document.
        $type = (string) $template->media_type;
        // Keyed by bot (ids are per bot), never a bare number that PHP would renumber.
        $bot = 'bot' . ($settings->bot_id ?? '');
        $fileId = $template->media_file_ids[$bot] ?? null;

        if ($fileId) {
            try {
                return $send($type, $fileId);
            } catch (TelegramException $e) {
                if (! $e->isFileIdRejected()) {
                    throw $e;
                }
                // Telegram forgot it: upload the file again below.
            }
        }

        $sent = $send($type, [
            'contents' => TelegramTemplate::mediaDisk()->readStream($template->media_path),
            'filename' => $template->media_name ?: basename((string) $template->media_path),
        ]);

        if ($id = TelegramClient::fileIdOf($sent, $type)) {
            $ids = (array) $template->media_file_ids;
            $ids[$bot] = $id;
            $template->forceFill(['media_file_ids' => $ids])->save();
        }

        return $sent;
    }

    private function failWithoutRetry(Message $message, string $reason): void
    {
        Log::warning('SendMessagesJob: message not sent', ['message_id' => $message->id, 'reason' => $reason]);

        $message->update(['status' => 'failed', 'error_message' => $reason, 'failed_at' => now()]);
        $message->increment('retry_count');

        $this->queueWebhook($message->fresh(), SendWebhookNotification::EVENT_FAILED);
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
