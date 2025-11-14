<?php

namespace App\Jobs;

use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendMessagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The message ID to process.
     *
     * @var int
     */
    protected $messageId;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $backoff = 60;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     *
     * @var int
     */
    public $maxExceptions = 3;

    /**
     * Create a new job instance.
     *
     * @param int $messageId
     */
    public function __construct(int $messageId)
    {
        $this->messageId = $messageId;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        $message = Message::find($this->messageId);

        if (!$message) {
            Log::warning('SendMessagesJob: Message not found', [
                'message_id' => $this->messageId,
            ]);
            return;
        }

        Log::info('SendMessagesJob: Starting to send message', [
            'message_id' => $message->id,
            'channel' => $message->channel,
            'recipient' => $message->recipient,
            'status' => $message->status,
        ]);

        try {
            // Update message status to sending
            $message->update([
                'status' => 'sending',
                'queued_at' => now(),
            ]);

            // Simulate sending process (5 seconds delay)
            sleep(5);

            // TODO: Implement actual sending logic based on channel
            // - For email: use SMTP settings
            // - For SMS: use SMS provider settings
            // - For WhatsApp: use Facebook/Meta API

            // Simulate successful send
            $message->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);

            Log::info('SendMessagesJob: Message sent successfully', [
                'message_id' => $message->id,
                'channel' => $message->channel,
                'recipient' => $message->recipient,
                'sent_at' => $message->sent_at,
            ]);

        } catch (\Exception $e) {
            Log::error('SendMessagesJob: Failed to send message', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Update message status to failed
            $message->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'failed_at' => now(),
            ]);

            // Increment retry count
            $message->increment('retry_count');

            // Re-throw exception to trigger retry mechanism
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendMessagesJob: Job failed permanently', [
            'message_id' => $this->messageId,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        $message = Message::find($this->messageId);

        if ($message) {
            $message->update([
                'status' => 'failed',
                'error_message' => 'Job failed after ' . $this->attempts() . ' attempts: ' . $exception->getMessage(),
                'failed_at' => now(),
            ]);
        }
    }
}

