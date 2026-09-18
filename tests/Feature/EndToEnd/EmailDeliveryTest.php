<?php

namespace Tests\Feature\EndToEnd;

use App\Jobs\SendMessagesJob;
use App\Jobs\SendSmtpTestEmail;
use App\Models\Business;
use App\Models\EmailTemplate;
use App\Models\Message;
use App\Models\SmtpSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The whole journey with nothing faked: a real SMTP server receives the mail.
 *
 * Needs Mailpit on localhost:1025 (API on 8025):
 *     docker run -d --name aninfpush_mailpit -p 1025:1025 -p 8025:8025 axllent/mailpit
 *
 * Skipped automatically when it is not running, so CI without it stays green.
 */
#[Group('smtp')]
class EmailDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const SMTP_HOST = '127.0.0.1';

    private const SMTP_PORT = 1025;

    private const API = 'http://127.0.0.1:8025/api/v1';

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->mailpitIsUp()) {
            $this->markTestSkipped('Mailpit is not running on ' . self::SMTP_HOST . ':' . self::SMTP_PORT);
        }

        $this->purgeMailbox();

        // The suite runs the queue synchronously, which would send inside the
        // HTTP request. Hold the job so each test can play the worker itself,
        // exactly as Horizon does in production.
        Queue::fake([SendMessagesJob::class, SendSmtpTestEmail::class]);
    }

    private function smtpFor(Business $business, array $overrides = []): SmtpSetting
    {
        return SmtpSetting::factory()->forBusiness($business)->create(array_merge([
            'host' => self::SMTP_HOST,
            'port' => self::SMTP_PORT,
            'encryption' => 'none',
            // Mailpit accepts any credentials.
            'username' => 'mailpit',
            'password' => 'mailpit',
            'from_email' => 'no-reply@acme-shop.test',
            'from_name' => 'Acme Shop',
            'verify_peer' => false,
        ], $overrides));
    }

    public function test_an_application_sends_a_real_templated_email(): void
    {
        /* 1. An application with its own SMTP configuration. */
        $business = Business::factory()->create(['name' => 'Acme Shop', 'status' => 'active']);
        $appSecret = $business->getPlainAppSecret();
        $this->smtpFor($business);

        $template = EmailTemplate::factory()->forBusiness($business)->active()->create([
            'name' => 'Order confirmation',
            'subject' => 'Order {{order_id}} confirmed',
            'html' => '<h1>Thanks {{name}}!</h1><p>Your order {{order_id}} totalling {{total}} is confirmed.</p>',
        ]);

        /* 2. It authenticates with app_id + app_secret. */
        $token = $this->postJson('/api/v1/auth/token', [
            'app_id' => $business->app_id,
            'app_secret' => $appSecret,
        ])->assertOk()->json('data.access_token');

        $this->assertNotEmpty($token);

        /* 3. It queues a templated email with that Bearer. */
        $queued = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/app/messages/email', [
                'template_id' => $template->id,
                'recipient_email' => 'jane@customer.test',
                'recipient_name' => 'Jane Customer',
                'variables' => ['name' => 'Jane', 'order_id' => 'A-1234', 'total' => '49.90 EUR'],
            ])
            ->assertStatus(201)
            ->json('data');

        $this->assertSame('queued', $queued['status']);
        Queue::assertPushedOn('emails', SendMessagesJob::class);

        /* 4. Horizon runs the job: the mail really leaves through that SMTP. */
        $message = Message::where('message_id', $queued['message_id'])->firstOrFail();
        (new SendMessagesJob($message->id))->handle();

        $this->assertSame('sent', $message->fresh()->status);
        $this->assertNotNull($message->fresh()->sent_at);

        /* 5. The SMTP server has it, rendered, with the right envelope. */
        $delivered = $this->latestMessage();

        $this->assertNotNull($delivered, 'No email reached the SMTP server.');
        $this->assertSame('Order A-1234 confirmed', $delivered['Subject']);
        $this->assertSame('jane@customer.test', $delivered['To'][0]['Address']);
        $this->assertSame('no-reply@acme-shop.test', $delivered['From']['Address']);
        $this->assertSame('Acme Shop', $delivered['From']['Name']);

        $body = $this->messageBody($delivered['ID']);
        $this->assertStringContainsString('Thanks Jane!', $body);
        $this->assertStringContainsString('A-1234', $body);
        $this->assertStringContainsString('49.90 EUR', $body);
        $this->assertStringNotContainsString('{{', $body);

        /* 6. The application can follow the delivery. */
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/app/messages/' . $queued['message_id'])
            ->assertOk()
            ->assertJsonPath('data.status', 'sent');
    }

    public function test_the_smtp_test_button_delivers_a_real_email(): void
    {
        $business = Business::factory()->create();
        $smtp = $this->smtpFor($business);

        $this->actingAsUser($this->globalManager());

        // The frontend action queues it...
        $this->postJson("/api/v1/businesses/{$business->id}/smtp-settings/{$smtp->id}/test", [
            'recipient_email' => 'admin@acme-shop.test',
        ])->assertOk();

        Queue::assertPushedOn('emails', SendSmtpTestEmail::class);

        // ...and the queue worker delivers it for real.
        (new SendSmtpTestEmail($smtp->fresh(), 'admin@acme-shop.test'))->handle();

        $delivered = $this->latestMessage();

        $this->assertNotNull($delivered, 'The test email never reached the SMTP server.');
        $this->assertSame('admin@acme-shop.test', $delivered['To'][0]['Address']);
        $this->assertStringContainsStringIgnoringCase('test', $delivered['Subject']);

        $smtp->refresh();
        $this->assertSame('success', $smtp->test_status);
        $this->assertNotNull($smtp->last_tested_at);
    }

    public function test_a_message_whose_smtp_is_unreachable_is_marked_failed(): void
    {
        $business = Business::factory()->create();
        // Nothing listens on port 1.
        $this->smtpFor($business, ['port' => 1, 'timeout' => 1]);

        $template = EmailTemplate::factory()->forBusiness($business)->active()->create([
            'subject' => 'Hello',
            'html' => '<p>Hello</p>',
            'plain_text' => 'Hello',
        ]);

        $token = $this->postJson('/api/v1/auth/token', [
            'app_id' => $business->app_id,
            'app_secret' => $business->getPlainAppSecret(),
        ])->json('data.access_token');

        $queued = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/app/messages/email', [
                'template_id' => $template->id,
                'recipient_email' => 'jane@customer.test',
            ])->assertStatus(201)->json('data');

        $message = Message::where('message_id', $queued['message_id'])->firstOrFail();

        try {
            (new SendMessagesJob($message->id))->handle();
            $this->fail('An unreachable SMTP host must fail the job.');
        } catch (\Throwable) {
            // Expected: rethrown so the queue retries.
        }

        $message->refresh();
        $this->assertSame('failed', $message->status);
        $this->assertNotEmpty($message->error_message);

        // And the application sees the failure.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/app/messages/' . $queued['message_id'])
            ->assertOk()
            ->assertJsonPath('data.status', 'failed');
    }

    /* ------------------------------------------------------------------ */

    private function mailpitIsUp(): bool
    {
        $connection = @fsockopen(self::SMTP_HOST, self::SMTP_PORT, $errno, $errstr, 1);

        if ($connection) {
            fclose($connection);

            return true;
        }

        return false;
    }

    private function purgeMailbox(): void
    {
        $context = stream_context_create(['http' => ['method' => 'DELETE', 'ignore_errors' => true, 'timeout' => 5]]);
        @file_get_contents(self::API . '/messages', false, $context);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestMessage(): ?array
    {
        $payload = json_decode((string) @file_get_contents(self::API . '/messages'), true);

        return $payload['messages'][0] ?? null;
    }

    private function messageBody(string $id): string
    {
        return (string) @file_get_contents('http://127.0.0.1:8025/api/v1/message/' . $id . '/raw');
    }
}
