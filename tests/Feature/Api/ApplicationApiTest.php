<?php

namespace Tests\Feature\Api;

use App\Jobs\SendMessagesJob;
use App\Models\Business;
use App\Models\EmailMessage;
use App\Models\EmailTemplate;
use App\Models\Message;
use App\Models\SmtpSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The machine-to-machine journey:
 * app_id + app_secret -> Bearer -> templated email -> queued -> SendMessagesJob.
 */
class ApplicationApiTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private string $appSecret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create(['status' => 'active']);
        // The clear secret only exists on the instance that generated it.
        $this->appSecret = $this->business->getPlainAppSecret();

        SmtpSetting::factory()->forBusiness($this->business)->create();
    }

    private function token(): string
    {
        return $this->postJson('/api/v1/auth/token', [
            'app_id' => $this->business->app_id,
            'app_secret' => $this->appSecret,
        ])->json('data.access_token');
    }

    private function asApplication(): static
    {
        return $this->withHeader('Authorization', 'Bearer ' . $this->token());
    }

    private function activeTemplate(array $attributes = []): EmailTemplate
    {
        return EmailTemplate::factory()->forBusiness($this->business)->active()->create(array_merge([
            'name' => 'Order confirmation',
            'subject' => 'Order {{order_id}} confirmed',
            'html' => '<p>Hello {{name}}, your order {{order_id}} is confirmed.</p>',
            'plain_text' => 'Hello {{name}}',
        ], $attributes));
    }

    /* --------------------------- 1. Authentication -------------------------- */

    public function test_an_application_exchanges_its_credentials_for_a_token(): void
    {
        $response = $this->postJson('/api/v1/auth/token', [
            'app_id' => $this->business->app_id,
            'app_secret' => $this->appSecret,
        ])->assertOk();

        $this->assertTrue($response->json('success'));
        $this->assertSame('Bearer', $response->json('data.token_type'));
        $this->assertSame($this->business->id, $response->json('data.business_id'));
        $this->assertNotEmpty($response->json('data.access_token'));
        $this->assertGreaterThan(0, $response->json('data.expires_in'));
    }

    public function test_the_secret_is_never_stored_or_returned_in_clear(): void
    {
        $fresh = Business::find($this->business->id);

        $this->assertNull($fresh->getPlainAppSecret());
        $this->assertNotSame($this->appSecret, $fresh->app_secret_hash);
        $this->assertArrayNotHasKey('app_secret', $fresh->toArray());
        $this->assertArrayNotHasKey('app_secret_hash', $fresh->toArray());

        // The stored value is a hash that still validates the original secret.
        $this->assertTrue($fresh->checkAppSecret($this->appSecret));
        $this->assertFalse($fresh->checkAppSecret('secret_wrong'));
    }

    public function test_a_wrong_secret_is_refused(): void
    {
        $this->postJson('/api/v1/auth/token', [
            'app_id' => $this->business->app_id,
            'app_secret' => 'secret_nope',
        ])->assertStatus(401);
    }

    public function test_an_unknown_app_id_answers_like_a_wrong_secret(): void
    {
        $unknown = $this->postJson('/api/v1/auth/token', [
            'app_id' => 'app_does_not_exist',
            'app_secret' => 'secret_nope',
        ])->assertStatus(401);

        $wrong = $this->postJson('/api/v1/auth/token', [
            'app_id' => $this->business->app_id,
            'app_secret' => 'secret_nope',
        ])->assertStatus(401);

        // Identical answers, so the endpoint cannot enumerate applications.
        $this->assertSame($unknown->json('message'), $wrong->json('message'));
    }

    public function test_an_inactive_application_cannot_get_a_token(): void
    {
        $this->business->update(['status' => 'inactive']);

        $this->postJson('/api/v1/auth/token', [
            'app_id' => $this->business->app_id,
            'app_secret' => $this->appSecret,
        ])->assertStatus(403);
    }

    public function test_token_requests_are_throttled(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/v1/auth/token', [
                'app_id' => $this->business->app_id,
                'app_secret' => 'secret_wrong',
            ])->assertStatus(401);
        }

        $this->postJson('/api/v1/auth/token', [
            'app_id' => $this->business->app_id,
            'app_secret' => 'secret_wrong',
        ])->assertStatus(429);
    }

    public function test_regenerating_credentials_invalidates_the_old_secret(): void
    {
        $this->actingAsUser($this->globalManager());

        $newSecret = $this->postJson("/api/v1/businesses/{$this->business->id}/regenerate-credentials")
            ->assertOk()
            ->json('data.app_secret');

        $this->assertNotSame($this->appSecret, $newSecret);

        $fresh = Business::find($this->business->id);
        $this->assertFalse($fresh->checkAppSecret($this->appSecret));
        $this->assertTrue($fresh->checkAppSecret($newSecret));
    }

    /* ------------------------- 2. Token boundaries -------------------------- */

    public function test_the_application_api_requires_a_token(): void
    {
        $this->postJson('/api/v1/app/messages/email', [])->assertStatus(401);
    }

    public function test_an_application_token_cannot_reach_the_management_api(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->token())
            ->getJson('/api/v1/businesses')
            ->assertStatus(401);

        $this->withHeader('Authorization', 'Bearer ' . $this->token())
            ->getJson('/api/v1/users')
            ->assertStatus(401);
    }

    public function test_a_user_token_cannot_reach_the_application_api(): void
    {
        $this->actingAsUser($this->globalManager())
            ->postJson('/api/v1/app/messages/email', [])
            ->assertStatus(401);
    }

    public function test_a_token_only_sees_its_own_application(): void
    {
        $other = Business::factory()->create();
        $otherTemplate = EmailTemplate::factory()->forBusiness($other)->active()->create();

        $this->asApplication()
            ->postJson('/api/v1/app/messages/email', [
                'template_id' => $otherTemplate->id,
                'recipient_email' => 'someone@example.test',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['template_id']]);

        $this->assertDatabaseCount('messages', 0);
    }

    /* ---------------------------- 3. Queue an email ------------------------- */

    public function test_a_templated_email_is_queued_and_dispatched(): void
    {
        Queue::fake();

        $template = $this->activeTemplate();

        $response = $this->asApplication()
            ->postJson('/api/v1/app/messages/email', [
                'template_id' => $template->id,
                'recipient_email' => 'customer@example.test',
                'recipient_name' => 'Jane Customer',
                'variables' => ['name' => 'Jane', 'order_id' => 'A-1234'],
                'campaign_id' => 'spring-sale',
            ])
            ->assertStatus(201);

        // The application is told the message is queued, not sent.
        $response->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.message_type', 'email')
            ->assertJsonPath('data.recipient_email', 'customer@example.test');

        $message = Message::first();
        $this->assertSame('queued', $message->status);
        $this->assertSame($this->business->id, $message->business_id);
        $this->assertSame('spring-sale', $message->campaign_id);

        // The template was rendered at queue time, variables substituted.
        $email = EmailMessage::first();
        $this->assertSame('Order A-1234 confirmed', $email->subject);
        $this->assertStringContainsString('Hello Jane', $email->content);
        $this->assertStringContainsString('order A-1234 is confirmed', $email->content);
        $this->assertSame($template->id, $email->email_template_id);
        $this->assertTrue((bool) $email->is_template);

        // And the job really goes to Horizon's `emails` queue.
        Queue::assertPushedOn('emails', SendMessagesJob::class);
    }

    public function test_queueing_increments_the_template_usage(): void
    {
        Bus::fake();
        $template = $this->activeTemplate();

        $this->asApplication()->postJson('/api/v1/app/messages/email', [
            'template_id' => $template->id,
            'recipient_email' => 'customer@example.test',
            'variables' => ['name' => 'Jane', 'order_id' => 'A-1'],
        ])->assertStatus(201);

        $this->assertSame(1, $template->fresh()->usage_count);
        $this->assertNotNull($template->fresh()->last_used_at);
    }

    public function test_missing_variables_are_refused_before_queueing(): void
    {
        Bus::fake();
        $template = $this->activeTemplate();

        $this->asApplication()
            ->postJson('/api/v1/app/messages/email', [
                'template_id' => $template->id,
                'recipient_email' => 'customer@example.test',
                'variables' => ['name' => 'Jane'], // order_id missing
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['variables']]);

        $this->assertDatabaseCount('messages', 0);
        Bus::assertNotDispatched(SendMessagesJob::class);
    }

    public function test_an_inactive_template_cannot_be_used(): void
    {
        Bus::fake();
        $template = EmailTemplate::factory()->forBusiness($this->business)->create(['status' => 'draft']);

        $this->asApplication()
            ->postJson('/api/v1/app/messages/email', [
                'template_id' => $template->id,
                'recipient_email' => 'customer@example.test',
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_queueing_is_refused_when_the_application_has_no_smtp(): void
    {
        Bus::fake();
        SmtpSetting::query()->delete();
        $template = $this->activeTemplate();

        $this->asApplication()
            ->postJson('/api/v1/app/messages/email', [
                'template_id' => $template->id,
                'recipient_email' => 'customer@example.test',
                'variables' => ['name' => 'Jane', 'order_id' => 'A-1'],
            ])
            ->assertStatus(503);

        // Nothing is queued that could only fail later.
        $this->assertDatabaseCount('messages', 0);
        Bus::assertNotDispatched(SendMessagesJob::class);
    }

    public function test_the_payload_is_validated(): void
    {
        $this->asApplication()
            ->postJson('/api/v1/app/messages/email', [
                'template_id' => 'not-an-id',
                'recipient_email' => 'not-an-email',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['template_id', 'recipient_email']]);
    }

    /* ---------------------------- 4. Status lookup -------------------------- */

    public function test_an_application_can_follow_its_message(): void
    {
        Bus::fake();
        $template = $this->activeTemplate();

        $messageId = $this->asApplication()->postJson('/api/v1/app/messages/email', [
            'template_id' => $template->id,
            'recipient_email' => 'customer@example.test',
            'variables' => ['name' => 'Jane', 'order_id' => 'A-1'],
        ])->json('data.message_id');

        $this->asApplication()
            ->getJson("/api/v1/app/messages/{$messageId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.recipient', 'customer@example.test');
    }

    public function test_an_application_cannot_read_another_application_message(): void
    {
        $other = Business::factory()->create();
        $foreign = Message::factory()->create(['business_id' => $other->id]);

        $this->asApplication()
            ->getJson("/api/v1/app/messages/{$foreign->message_id}")
            ->assertStatus(404);
    }

    public function test_an_application_lists_only_its_active_templates(): void
    {
        $this->activeTemplate(['name' => 'Active one']);
        EmailTemplate::factory()->forBusiness($this->business)->create(['name' => 'Draft one', 'status' => 'draft']);
        EmailTemplate::factory()->create(['name' => 'Someone else']);

        $templates = $this->asApplication()
            ->getJson('/api/v1/app/templates/email')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $templates);
        $this->assertSame('Active one', $templates[0]['name']);
    }
}
