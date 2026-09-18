<?php

namespace Tests\Feature\Jobs;

use App\Jobs\SendMessagesJob;
use App\Jobs\SendWebhookNotification;
use App\Models\Business;
use App\Models\EmailMessage;
use App\Models\Message;
use App\Models\SmtpSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Delivery notifications pushed to the application's own endpoint.
 */
class WebhookNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create([
            'webhook_url' => 'https://customer.test/hooks/aninfpush',
            'webhook_secret' => 'whsec_super_secret',
        ]);

        SmtpSetting::factory()->forBusiness($this->business)->create();
    }

    private function message(array $overrides = []): Message
    {
        $message = Message::factory()->create(array_merge([
            'business_id' => $this->business->id,
            'message_type' => 'email',
            'status' => 'queued',
            'error_message' => null,
            'sent_at' => null,
            'retry_count' => 0,
        ], $overrides));

        EmailMessage::create([
            'message_id' => $message->id,
            'is_template' => true,
            'recipient_email' => 'customer@example.test',
            'subject' => 'Order A-1234 confirmed',
            'content' => '<p>Hello</p>',
        ]);

        return $message;
    }

    /* ----------------------------- Dispatching ----------------------------- */

    public function test_a_successful_send_queues_a_webhook(): void
    {
        Mail::fake();
        Queue::fake([SendWebhookNotification::class]);

        (new SendMessagesJob($this->message()->id))->handle();

        Queue::assertPushedOn('webhooks', SendWebhookNotification::class);
    }

    public function test_a_failed_send_queues_a_webhook(): void
    {
        Mail::fake();
        Queue::fake([SendWebhookNotification::class]);
        SmtpSetting::query()->update(['is_active' => false]);

        try {
            (new SendMessagesJob($this->message()->id))->handle();
        } catch (\Throwable) {
            // Expected.
        }

        Queue::assertPushedOn('webhooks', SendWebhookNotification::class);
    }

    public function test_an_application_without_a_webhook_url_is_not_notified(): void
    {
        Mail::fake();
        Queue::fake([SendWebhookNotification::class]);
        $this->business->update(['webhook_url' => null]);

        (new SendMessagesJob($this->message()->id))->handle();

        Queue::assertNotPushed(SendWebhookNotification::class);
    }

    public function test_an_application_can_subscribe_to_failures_only(): void
    {
        Mail::fake();
        Queue::fake([SendWebhookNotification::class]);
        $this->business->update(['webhook_events' => [SendWebhookNotification::EVENT_FAILED]]);

        (new SendMessagesJob($this->message()->id))->handle();

        Queue::assertNotPushed(SendWebhookNotification::class);
    }

    /* ------------------------------ Delivering ----------------------------- */

    public function test_the_payload_carries_the_delivery_details(): void
    {
        Http::fake(['customer.test/*' => Http::response('', 200)]);
        $message = $this->message(['status' => 'sent', 'sent_at' => now()]);

        (new SendWebhookNotification($message->id, SendWebhookNotification::EVENT_SENT))->handle();

        Http::assertSent(function (Request $request) use ($message) {
            $body = $request->data();

            return $request->url() === 'https://customer.test/hooks/aninfpush'
                && $body['event'] === 'message.sent'
                && $body['data']['message_id'] === $message->message_id
                && $body['data']['status'] === 'sent'
                && $body['data']['recipient'] === 'customer@example.test'
                && $body['data']['subject'] === 'Order A-1234 confirmed';
        });
    }

    /**
     * A failed message must carry its reason, so the application does not have
     * to poll the API to learn why.
     */
    public function test_a_failure_payload_carries_the_error_message(): void
    {
        Http::fake(['customer.test/*' => Http::response('', 200)]);
        $message = $this->message([
            'status' => 'failed',
            'error_message' => 'Connection could not be established with host smtp.example.test:587',
            'failed_at' => now(),
            'retry_count' => 3,
        ]);

        (new SendWebhookNotification($message->id, SendWebhookNotification::EVENT_FAILED))->handle();

        Http::assertSent(function (Request $request) {
            $data = $request->data()['data'];

            return $data['status'] === 'failed'
                && str_contains($data['error_message'], 'Connection could not be established')
                && $data['retry_count'] === 3
                && $data['failed_at'] !== null;
        });
    }

    public function test_the_payload_is_signed_with_the_application_secret(): void
    {
        Http::fake(['customer.test/*' => Http::response('', 200)]);
        $message = $this->message(['status' => 'sent']);

        (new SendWebhookNotification($message->id, SendWebhookNotification::EVENT_SENT))->handle();

        Http::assertSent(function (Request $request) {
            $signature = $request->header('X-AninfPush-Signature')[0] ?? '';
            $expected = 'sha256=' . hash_hmac('sha256', $request->body(), 'whsec_super_secret');

            // The signature must cover the exact bytes that were sent.
            return hash_equals($expected, $signature);
        });
    }

    public function test_the_event_and_delivery_id_are_in_the_headers(): void
    {
        Http::fake(['customer.test/*' => Http::response('', 200)]);
        $message = $this->message(['status' => 'sent']);

        (new SendWebhookNotification($message->id, SendWebhookNotification::EVENT_SENT))->handle();

        Http::assertSent(fn (Request $request) => ($request->header('X-AninfPush-Event')[0] ?? null) === 'message.sent'
            && ($request->header('X-AninfPush-Delivery')[0] ?? null) === $message->message_id);
    }

    public function test_no_signature_is_sent_when_no_secret_is_configured(): void
    {
        Http::fake(['customer.test/*' => Http::response('', 200)]);
        $this->business->update(['webhook_secret' => null]);
        $message = $this->message(['status' => 'sent']);

        (new SendWebhookNotification($message->id, SendWebhookNotification::EVENT_SENT))->handle();

        Http::assertSent(fn (Request $request) => empty($request->header('X-AninfPush-Signature')));
    }

    public function test_a_rejecting_endpoint_makes_the_job_retry(): void
    {
        Http::fake(['customer.test/*' => Http::response('nope', 500)]);
        $message = $this->message(['status' => 'sent']);

        $this->expectException(\RuntimeException::class);

        (new SendWebhookNotification($message->id, SendWebhookNotification::EVENT_SENT))->handle();
    }

    public function test_the_webhook_queue_is_separate_from_delivery(): void
    {
        // A slow customer endpoint must never hold up sending real messages.
        $this->assertSame('webhooks', (new SendWebhookNotification(1, 'message.sent'))->queue);
        $this->assertSame('emails', (new SendMessagesJob(1))->queue);
    }

    /* --------------------- Isolation from the delivery --------------------- */

    /**
     * The whole point of the separate job: a customer endpoint that is down must
     * never turn a delivered email into a failed one, nor make the delivery job
     * retry and send the email twice.
     */
    public function test_a_broken_webhook_endpoint_does_not_fail_the_message(): void
    {
        Mail::fake();
        // No Queue::fake: the test queue is synchronous, so the webhook job runs
        // inline, exactly the situation where the two could bleed into each other.
        Http::fake(['customer.test/*' => Http::response('boom', 500)]);

        $message = $this->message();

        (new SendMessagesJob($message->id))->handle();

        $message->refresh();
        $this->assertSame('sent', $message->status, 'The email was delivered, the message must stay sent.');
        $this->assertNull($message->error_message);
        $this->assertNotNull($message->sent_at);

        // The webhook failure is recorded on its own fields.
        $this->assertSame('failed', $message->webhook_status);
        $this->assertStringContainsString('HTTP 500', $message->webhook_error);
    }

    public function test_an_unreachable_webhook_host_does_not_fail_the_message(): void
    {
        Mail::fake();
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Could not resolve host'));

        $message = $this->message();

        (new SendMessagesJob($message->id))->handle();

        $message->refresh();
        $this->assertSame('sent', $message->status);
        $this->assertSame('failed', $message->webhook_status);
        $this->assertStringContainsString('Could not resolve host', $message->webhook_error);
    }

    /* ---------------------- Webhook outcome on the message ------------------ */

    public function test_a_delivered_webhook_is_recorded(): void
    {
        Http::fake(['customer.test/*' => Http::response('', 200)]);
        $message = $this->message(['status' => 'sent']);

        (new SendWebhookNotification($message->id, SendWebhookNotification::EVENT_SENT))->handle();

        $message->refresh();
        $this->assertSame('delivered', $message->webhook_status);
        $this->assertNull($message->webhook_error);
        $this->assertSame(1, $message->webhook_attempts);
        $this->assertNotNull($message->webhook_last_attempt_at);
    }

    public function test_the_rejection_body_is_kept_for_diagnosis(): void
    {
        Http::fake(['customer.test/*' => Http::response('invalid signature', 401)]);
        $message = $this->message(['status' => 'sent']);

        try {
            (new SendWebhookNotification($message->id, SendWebhookNotification::EVENT_SENT))->handle();
        } catch (\RuntimeException) {
            // Expected: rethrown so the queue retries.
        }

        $message->refresh();
        $this->assertSame('failed', $message->webhook_status);
        $this->assertStringContainsString('HTTP 401', $message->webhook_error);
        $this->assertStringContainsString('invalid signature', $message->webhook_error);
    }

    public function test_attempts_are_counted_across_retries(): void
    {
        Http::fake(['customer.test/*' => Http::response('', 503)]);
        $message = $this->message(['status' => 'sent']);

        foreach (range(1, 3) as $ignored) {
            try {
                (new SendWebhookNotification($message->id, SendWebhookNotification::EVENT_SENT))->handle();
            } catch (\RuntimeException) {
                // Expected.
            }
        }

        $this->assertSame(3, $message->fresh()->webhook_attempts);
    }

    public function test_giving_up_is_recorded_on_the_message(): void
    {
        $message = $this->message(['status' => 'sent']);

        (new SendWebhookNotification($message->id, SendWebhookNotification::EVENT_SENT))
            ->failed(new \RuntimeException('Endpoint answered HTTP 500'));

        $message->refresh();
        $this->assertSame('failed', $message->webhook_status);
        $this->assertStringContainsString('Gave up', $message->webhook_error);
        $this->assertStringContainsString('HTTP 500', $message->webhook_error);
    }

    public function test_a_message_without_a_webhook_stays_not_applicable(): void
    {
        Mail::fake();
        $this->business->update(['webhook_url' => null]);

        (new SendMessagesJob($this->message()->id))->handle();

        $this->assertSame('not_applicable', Message::first()->webhook_status);
    }

    public function test_the_secret_is_encrypted_at_rest(): void
    {
        $raw = $this->business->getRawOriginal('webhook_secret');

        $this->assertNotSame('whsec_super_secret', $raw);
        $this->assertSame('whsec_super_secret', $this->business->fresh()->webhook_secret);
        $this->assertArrayNotHasKey('webhook_secret', $this->business->toArray());
    }
}
