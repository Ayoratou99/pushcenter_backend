<?php

namespace Tests\Feature\Api;

use App\Jobs\SendMessagesJob;
use App\Models\Business;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The console only reads messages: applications send them through the
 * /v1/app API. Nothing here may answer success without doing the work.
 */
class MessageEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->actingAsUser($this->globalManager());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function removedEndpoints(): array
    {
        return [
            'send email' => ['POST', '/api/v1/messages/email'],
            'send sms' => ['POST', '/api/v1/messages/sms'],
            'send whatsapp' => ['POST', '/api/v1/messages/whatsapp'],
            'create' => ['POST', '/api/v1/messages'],
            'update' => ['PUT', '/api/v1/messages/1'],
        ];
    }

    #[DataProvider('removedEndpoints')]
    public function test_the_endpoints_that_faked_a_delivery_are_gone(string $method, string $uri): void
    {
        Message::factory()->create(['id' => 1, 'business_id' => $this->business->id]);

        $status = $this->json($method, $uri, [
            'business_id' => $this->business->id,
            'recipient_email' => 'someone@customer.test',
            'status' => 'delivered',
        ])->status();

        $this->assertContains($status, [404, 405]);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_retrying_a_failed_email_really_queues_it_again(): void
    {
        Queue::fake();
        $message = Message::factory()->failed()->create([
            'business_id' => $this->business->id,
            'message_type' => 'email',
            'retry_count' => 3,
        ]);

        $this->postJson("/api/v1/messages/{$message->id}/retry")
            ->assertOk()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.retry_count', 4)
            ->assertJsonPath('data.error_message', null);

        Queue::assertPushedOn('emails', SendMessagesJob::class);
        $this->assertSame('queued', $message->fresh()->status);
    }

    public function test_retrying_a_channel_that_does_not_send_yet_says_so(): void
    {
        Queue::fake();
        $message = Message::factory()->failed()->create([
            'business_id' => $this->business->id,
            'message_type' => 'sms',
        ]);

        $this->postJson("/api/v1/messages/{$message->id}/retry")->assertStatus(501);

        Queue::assertNothingPushed();
        $this->assertSame('failed', $message->fresh()->status);
    }

    public function test_only_failed_messages_can_be_retried(): void
    {
        Queue::fake();
        $message = Message::factory()->sent()->create([
            'business_id' => $this->business->id,
            'message_type' => 'email',
        ]);

        $this->postJson("/api/v1/messages/{$message->id}/retry")->assertStatus(400);

        Queue::assertNothingPushed();
    }

    public function test_the_read_side_still_answers(): void
    {
        $message = Message::factory()->create(['business_id' => $this->business->id]);

        $this->getJson('/api/v1/messages')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson("/api/v1/messages/{$message->id}")->assertOk();
        $this->getJson('/api/v1/messages/stats')->assertOk();
    }
}
