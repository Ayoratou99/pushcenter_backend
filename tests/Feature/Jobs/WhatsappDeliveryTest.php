<?php

namespace Tests\Feature\Jobs;

use App\Jobs\SendMessagesJob;
use App\Jobs\SendWebhookNotification;
use App\Jobs\TrackWhatsappDelivery;
use App\Models\Business;
use App\Models\Message;
use App\Models\WhatsAppMessage;
use App\Models\WhatsappSetting;
use App\Models\WhatsappTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesAyosPush;
use Tests\TestCase;

/**
 * SendMessagesJob hands WhatsApp messages to AyosPush; TrackWhatsappDelivery
 * reads the outcome AyosPush reports afterwards.
 */
class WhatsappDeliveryTest extends TestCase
{
    use FakesAyosPush;
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        // Only the jobs chained from the one under test are faked.
        Queue::fake([TrackWhatsappDelivery::class, SendWebhookNotification::class]);

        $this->business = Business::factory()->create([
            'status' => 'active',
            'webhook_url' => 'https://customer.test/hooks',
        ]);
        WhatsappSetting::factory()->connected()->forBusiness($this->business)->create();
    }

    private function queuedMessage(array $whatsapp = [], array $message = []): Message
    {
        $template = WhatsappTemplate::factory()->forBusiness($this->business)->approved()->create([
            'body' => 'Bonjour {{1}}, votre commande {{2}} est confirmée.',
            'provider_template_id' => 55,
            'provider_template_name' => 'order_confirmation_biz3_0922101530',
        ]);

        $model = Message::factory()->create(array_merge([
            'business_id' => $this->business->id,
            'message_type' => 'whatsapp',
            'status' => 'queued',
            'sent_at' => null,
            'delivered_at' => null,
        ], $message));

        WhatsAppMessage::create(array_merge([
            'message_id' => $model->id,
            'whatsapp_template_id' => $template->id,
            'is_template' => true,
            'recipient_number' => '+24177123456',
            'template_variables' => ['1' => 'Awa', '2' => 'CMD-001'],
            'content' => 'Bonjour Awa, votre commande CMD-001 est confirmée.',
            'sender_phone_number_id' => '111222333444555',
            'provider_template_name' => 'order_confirmation_biz3_0922101530',
        ], $whatsapp));

        return $model;
    }

    private function fakeSendAccepted(): void
    {
        $this->fakeAyosPush([
            'POST /messages/template' => fn () => Http::response([
                'success' => true,
                'data' => [
                    'request_id' => 9001,
                    'status' => 'queued',
                    'recipient' => '+24177123456',
                    'template' => 'order_confirmation_biz3_0922101530',
                ],
                'message' => 'Message queued for delivery',
            ], 202),
        ]);
    }

    private function eventOf(SendWebhookNotification $job): string
    {
        return (fn () => $this->event)->call($job);
    }

    private function runSend(Message $message): void
    {
        (new SendMessagesJob($message->id))->handle();
    }

    /* ------------------------------ Sending ----------------------------- */

    public function test_the_message_is_handed_to_ayospush_as_it_expects(): void
    {
        $this->fakeSendAccepted();
        $message = $this->queuedMessage();

        $this->runSend($message);

        $sent = $this->ayosPushRequests('POST /messages/template')->first();
        $this->assertNotNull($sent);
        $this->assertTrue($sent->hasHeader('Authorization', 'Bearer ' . $this->ayosPushToken));
        $this->assertSame('111222333444555', $sent['phone_number_id']);
        $this->assertSame('+24177123456', $sent['recipient_number']);
        $this->assertSame('order_confirmation_biz3_0922101530', $sent['template_name']);
        // A JSON *string*: AyosPush's `json` rule rejects an object.
        $this->assertSame('{"1":"Awa","2":"CMD-001"}', $sent['variables']);

        $message->refresh();
        $this->assertSame('sending', $message->status);
        $this->assertSame(9001, $message->whatsappMessage->provider_request_id);
        $this->assertSame('queued', $message->whatsappMessage->provider_status);

        Queue::assertPushedOn('whatsapp', TrackWhatsappDelivery::class, fn (TrackWhatsappDelivery $job) => $job->check === 1);
        Queue::assertNotPushed(SendWebhookNotification::class);
    }

    public function test_a_message_already_accepted_is_never_sent_twice(): void
    {
        $this->fakeSendAccepted();
        $message = $this->queuedMessage(['provider_request_id' => 9001]);

        $this->runSend($message);

        $this->assertCount(0, $this->ayosPushRequests('POST /messages/template'));
        $this->assertSame('sending', $message->fresh()->status);
        Queue::assertPushed(TrackWhatsappDelivery::class);
    }

    public function test_a_refusal_fails_the_message_with_the_reason_and_no_retry(): void
    {
        $this->fakeAyosPush([
            'POST /messages/template' => fn () => $this->ayosPushError(400, 'Template is not approved by Facebook. Status: PENDING', [
                'errors' => ['facebook_status' => 'PENDING', 'status' => 'pending'],
            ]),
        ]);
        $message = $this->queuedMessage();

        $this->runSend($message);

        $message->refresh();
        $this->assertSame('failed', $message->status);
        $this->assertSame('AyosPush refused the request: Template is not approved by Facebook. Status: PENDING', $message->error_message);
        Queue::assertPushed(SendWebhookNotification::class, fn ($job) => $this->eventOf($job) === SendWebhookNotification::EVENT_FAILED);
        Queue::assertNotPushed(TrackWhatsappDelivery::class);
    }

    public function test_the_meta_messaging_tier_is_a_failure_not_a_wait(): void
    {
        $this->fakeAyosPush([
            'POST /messages/template' => fn () => $this->ayosPushError(429, 'Palier de messagerie atteint : ce numéro peut ouvrir 250 conversations par 24 heures (TIER_250) et en a déjà ouvert 250.', [
                'errors' => ['messaging_tier' => 'TIER_250', 'limit' => 250, 'used' => 250, 'slot_frees_at' => '2026-09-22T18:00:00+00:00'],
            ]),
        ]);
        $message = $this->queuedMessage();

        $this->runSend($message);

        $this->assertSame('failed', $message->fresh()->status);
        $this->assertStringContainsString('TIER_250', $message->fresh()->error_message);
    }

    public function test_the_ayospush_rate_limit_is_waited_for(): void
    {
        $this->fakeAyosPush([
            'POST /messages/template' => fn () => Http::response(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '12']),
        ]);
        $message = $this->queuedMessage();

        $this->runSend($message);

        $message->refresh();
        $this->assertSame('queued', $message->status);
        $this->assertStringStartsWith('Waiting for the AyosPush rate limit', $message->error_message);
        Queue::assertNotPushed(SendWebhookNotification::class);
    }

    public function test_an_unreachable_ayospush_is_retried(): void
    {
        config(['services.ayospush.base_url' => $this->ayosPushUrl]);
        Http::fake(['*' => Http::failedConnection('cURL error 7: Failed to connect to ayospush.test port 443: Connection refused')]);
        $message = $this->queuedMessage();

        try {
            $this->runSend($message);
            $this->fail('The job should throw so the queue retries it.');
        } catch (\App\Services\AyosPush\AyosPushException $e) {
            $this->assertSame(0, $e->status);
        }

        $this->assertSame('queued', $message->fresh()->status);
    }

    public function test_no_answer_after_sending_is_not_retried_blindly(): void
    {
        $this->fakeAyosPush([
            'POST /messages/template' => fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out after 20001 milliseconds with 0 bytes received'),
        ]);
        $message = $this->queuedMessage();

        $this->runSend($message);

        $message->refresh();
        $this->assertSame('failed', $message->status);
        $this->assertStringContainsString('AyosPush may have received the message', $message->error_message);
    }

    public function test_a_missing_sender_or_configuration_fails_explicitly(): void
    {
        $this->fakeAyosPush();
        $message = $this->queuedMessage(['sender_phone_number_id' => null]);
        WhatsappSetting::query()->update(['default_phone_number_id' => null]);

        $this->runSend($message);

        $this->assertSame('No WhatsApp sender number is selected for this application.', $message->fresh()->error_message);
        Http::assertNothingSent();
    }

    /* ------------------------------ Tracking ---------------------------- */

    private function sendingMessage(): Message
    {
        return tap($this->queuedMessage(['provider_request_id' => 9001, 'provider_status' => 'queued']))
            ->update(['status' => 'sending']);
    }

    private function fakeStatus(string $status, array $extra = []): void
    {
        $this->fakeAyosPush([
            'GET /messages/9001/status' => fn () => Http::response(['success' => true, 'data' => array_merge([
                'request_id' => 9001,
                'status' => $status,
                'error_message' => null,
                'response_body' => null,
                'created_at' => '2026-09-22T10:00:00+00:00',
                'updated_at' => '2026-09-22T10:00:05+00:00',
            ], $extra), 'message' => 'Success']),
        ]);
    }

    public function test_success_marks_the_message_sent_and_notifies_the_application(): void
    {
        $this->fakeStatus('success', ['response_body' => [
            'message_id' => 'wamid.HBgMMjQxNzcxMjM0NTY3FQIAERgSQ0Q',
            'recipient' => '+24177123456',
            'template' => 'order_confirmation_biz3_0922101530',
        ]]);
        $message = $this->sendingMessage();

        (new TrackWhatsappDelivery($message->id))->handle();

        $message->refresh();
        $this->assertSame('sent', $message->status);
        $this->assertNotNull($message->sent_at);
        $this->assertSame('wamid.HBgMMjQxNzcxMjM0NTY3FQIAERgSQ0Q', $message->whatsappMessage->provider_message_id);
        $this->assertSame('success', $message->whatsappMessage->provider_status);
        Queue::assertPushed(SendWebhookNotification::class, fn ($job) => $this->eventOf($job) === SendWebhookNotification::EVENT_SENT);
    }

    public function test_a_failure_reported_by_ayospush_keeps_its_reason(): void
    {
        $this->fakeStatus('failed', ['error_message' => '(#131026) Message undeliverable']);
        $message = $this->sendingMessage();

        (new TrackWhatsappDelivery($message->id))->handle();

        $message->refresh();
        $this->assertSame('failed', $message->status);
        $this->assertSame('WhatsApp delivery failed: (#131026) Message undeliverable', $message->error_message);
        Queue::assertPushed(SendWebhookNotification::class, fn ($job) => $this->eventOf($job) === SendWebhookNotification::EVENT_FAILED);
    }

    public function test_a_message_still_waiting_is_checked_again_later(): void
    {
        $this->fakeStatus('queued');
        $message = $this->sendingMessage();

        (new TrackWhatsappDelivery($message->id, 2))->handle();

        $this->assertSame('sending', $message->fresh()->status);
        Queue::assertPushed(TrackWhatsappDelivery::class, fn (TrackWhatsappDelivery $job) => $job->check === 3);
    }

    public function test_tracking_gives_up_after_the_last_check(): void
    {
        $this->fakeStatus('pending');
        $message = $this->sendingMessage();

        (new TrackWhatsappDelivery($message->id, count(TrackWhatsappDelivery::DELAYS)))->handle();

        $message->refresh();
        $this->assertSame('failed', $message->status);
        $this->assertStringStartsWith('No confirmation from AyosPush after', $message->error_message);
        Queue::assertNotPushed(TrackWhatsappDelivery::class);
    }

    public function test_a_request_unknown_to_ayospush_fails_the_message(): void
    {
        $this->fakeAyosPush(['GET /messages/9001/status' => fn () => $this->ayosPushError(404, 'Request not found')]);
        $message = $this->sendingMessage();

        (new TrackWhatsappDelivery($message->id))->handle();

        $this->assertSame('failed', $message->fresh()->status);
    }

    public function test_a_closed_message_is_left_alone(): void
    {
        $this->fakeStatus('success');
        $message = $this->sendingMessage();
        $message->update(['status' => 'cancelled']);

        (new TrackWhatsappDelivery($message->id))->handle();

        Http::assertNothingSent();
        $this->assertSame('cancelled', $message->fresh()->status);
    }

    /* ------------------------- Console retry ---------------------------- */

    public function test_a_failed_whatsapp_message_can_be_retried_from_the_console(): void
    {
        Queue::fake();
        $message = $this->queuedMessage(['provider_request_id' => 9001, 'provider_status' => 'failed'], [
            'status' => 'failed',
            'error_message' => 'WhatsApp delivery failed: (#131026) Message undeliverable',
        ]);
        $this->actingAsUser($this->globalManager());

        $this->postJson("/api/v1/messages/{$message->id}/retry")
            ->assertOk()
            ->assertJsonPath('data.status', 'queued');

        // A new attempt is a new AyosPush request.
        $this->assertNull($message->fresh()->whatsappMessage->provider_request_id);
        Queue::assertPushedOn('whatsapp', SendMessagesJob::class);
    }

    /* ------------------------------ Webhook ----------------------------- */

    public function test_the_webhook_carries_the_whatsapp_recipient_and_template(): void
    {
        Http::fake(['customer.test/*' => Http::response('ok', 200)]);
        $message = $this->queuedMessage([], ['status' => 'sent', 'sent_at' => now()]);

        (new SendWebhookNotification($message->id, SendWebhookNotification::EVENT_SENT))->handle();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://customer.test/hooks'
            && $request['data']['message_type'] === 'whatsapp'
            && $request['data']['recipient'] === '+24177123456'
            && $request['data']['template'] === 'order_confirmation_biz3_0922101530');
    }
}
