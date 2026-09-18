<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\EmailMessage;
use App\Models\Message;
use App\Models\SmtpSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Operational alerts shown in the dashboard bell.
 */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create(['name' => 'Acme Shop']);
    }

    private function failedMessage(array $overrides = []): Message
    {
        $message = Message::factory()->create(array_merge([
            'business_id' => $this->business->id,
            'message_type' => 'email',
            'status' => 'failed',
            'error_message' => 'Connection could not be established with host smtp.example.test:587',
            'failed_at' => now()->subMinutes(5),
        ], $overrides));

        EmailMessage::create([
            'message_id' => $message->id,
            'is_template' => true,
            'recipient_email' => 'jane@customer.test',
            'subject' => 'Order A-1234',
        ]);

        return $message;
    }

    public function test_a_failed_message_becomes_a_notification(): void
    {
        $this->actingAsUser($this->globalManager());
        $this->failedMessage();

        $response = $this->getJson('/api/v1/notifications')->assertOk();

        $notification = $response->json('data.notifications.0');

        $this->assertSame('message.failed', $notification['type']);
        $this->assertSame('error', $notification['severity']);
        $this->assertStringContainsString('Email delivery failed', $notification['title']);
        $this->assertStringContainsString('jane@customer.test', $notification['description']);
        $this->assertStringContainsString('Connection could not be established', $notification['description']);
        $this->assertSame('Acme Shop', $notification['business']['name']);
        $this->assertSame(1, $response->json('data.unread_count'));
    }

    public function test_a_failed_webhook_becomes_a_notification(): void
    {
        $this->actingAsUser($this->globalManager());

        Message::factory()->create([
            'business_id' => $this->business->id,
            'status' => 'sent',
            'webhook_status' => 'failed',
            'webhook_error' => 'Endpoint answered HTTP 500',
            'webhook_last_attempt_at' => now()->subMinutes(2),
        ]);

        $notification = $this->getJson('/api/v1/notifications')->assertOk()
            ->json('data.notifications.0');

        $this->assertSame('webhook.failed', $notification['type']);
        $this->assertSame('warning', $notification['severity']);
        $this->assertStringContainsString('HTTP 500', $notification['description']);
    }

    public function test_a_failing_smtp_configuration_becomes_a_notification(): void
    {
        $this->actingAsUser($this->globalManager());

        SmtpSetting::factory()->forBusiness($this->business)->create([
            'name' => 'Primary SMTP',
            'test_status' => 'failed',
            'test_error' => 'Authentication failed',
            'last_tested_at' => now()->subMinute(),
        ]);

        $notification = $this->getJson('/api/v1/notifications')->assertOk()
            ->json('data.notifications.0');

        $this->assertSame('smtp.failed', $notification['type']);
        $this->assertStringContainsString('Authentication failed', $notification['description']);
    }

    public function test_healthy_activity_produces_nothing(): void
    {
        $this->actingAsUser($this->globalManager());

        Message::factory()->create([
            'business_id' => $this->business->id,
            'status' => 'sent',
            'webhook_status' => 'delivered',
        ]);

        $response = $this->getJson('/api/v1/notifications')->assertOk();

        $this->assertCount(0, $response->json('data.notifications'));
        $this->assertSame(0, $response->json('data.unread_count'));
    }

    public function test_old_events_drop_out(): void
    {
        $this->actingAsUser($this->globalManager());
        $this->failedMessage(['failed_at' => now()->subDays(30)]);

        $this->assertCount(0, $this->getJson('/api/v1/notifications')->json('data.notifications'));
    }

    public function test_newest_first(): void
    {
        $this->actingAsUser($this->globalManager());
        $this->failedMessage(['failed_at' => now()->subHours(3)]);
        $recent = $this->failedMessage(['failed_at' => now()->subMinute()]);

        $notifications = $this->getJson('/api/v1/notifications')->json('data.notifications');

        $this->assertSame('message-failed-' . $recent->id, $notifications[0]['id']);
    }

    /* ------------------------------- Read state ----------------------------- */

    public function test_marking_as_read_clears_the_badge(): void
    {
        $user = $this->globalManager();
        $this->actingAsUser($user);
        $this->failedMessage();

        $this->assertSame(1, $this->getJson('/api/v1/notifications')->json('data.unread_count'));

        $this->postJson('/api/v1/notifications/read')->assertOk();

        $response = $this->getJson('/api/v1/notifications')->assertOk();

        // Still listed, but no longer counted.
        $this->assertCount(1, $response->json('data.notifications'));
        $this->assertSame(0, $response->json('data.unread_count'));
        $this->assertFalse($response->json('data.notifications.0.is_unread'));
        $this->assertNotNull($user->fresh()->notifications_read_at);
    }

    public function test_something_newer_becomes_unread_again(): void
    {
        $this->actingAsUser($this->globalManager());
        $this->failedMessage(['failed_at' => now()->subHours(2)]);

        $this->postJson('/api/v1/notifications/read')->assertOk();
        $this->assertSame(0, $this->getJson('/api/v1/notifications')->json('data.unread_count'));

        // A later second, so the comparison is unambiguous.
        $this->travel(5)->seconds();
        $this->failedMessage(['failed_at' => now()]);

        $this->assertSame(1, $this->getJson('/api/v1/notifications')->json('data.unread_count'));
    }

    public function test_the_read_marker_is_per_user(): void
    {
        $first = $this->globalManager();
        $second = $this->globalManager();
        $this->failedMessage();

        $this->actingAsUser($first)->postJson('/api/v1/notifications/read')->assertOk();

        $this->assertSame(0, $this->actingAsUser($first)->getJson('/api/v1/notifications')->json('data.unread_count'));
        $this->assertSame(1, $this->actingAsUser($second)->getJson('/api/v1/notifications')->json('data.unread_count'));
    }

    /* -------------------------------- Scoping ------------------------------- */

    public function test_a_restricted_manager_only_sees_their_applications(): void
    {
        $mine = Business::factory()->create(['name' => 'Mine']);
        $this->failedMessage();                                   // Acme Shop
        $message = $this->failedMessage(['business_id' => $mine->id]);

        $this->actingAsUser($this->restrictedManager([$mine->id]));

        $notifications = $this->getJson('/api/v1/notifications')->assertOk()->json('data.notifications');

        $this->assertCount(1, $notifications);
        $this->assertSame('message-failed-' . $message->id, $notifications[0]['id']);
    }

    public function test_notifications_require_authentication(): void
    {
        $this->withHeader('Authorization', 'Bearer invalid')
            ->getJson('/api/v1/notifications')
            ->assertStatus(401);
    }
}
