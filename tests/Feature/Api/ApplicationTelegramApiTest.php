<?php

namespace Tests\Feature\Api;

use App\Jobs\SendMessagesJob;
use App\Jobs\SendWebhookNotification;
use App\Models\Business;
use App\Models\Message;
use App\Models\TelegramMessage;
use App\Models\TelegramSetting;
use App\Models\TelegramSubscriber;
use App\Models\TelegramTemplate;
use App\Services\Telegram\TelegramException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesTelegram;
use Tests\TestCase;

/**
 * An application invites its users to its bot, then sends them templated
 * Telegram messages; SendMessagesJob delivers them with sendMessage.
 */
class ApplicationTelegramApiTest extends TestCase
{
    use FakesTelegram;
    use RefreshDatabase;

    private Business $business;

    private string $appSecret;

    private TelegramSubscriber $awa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create(['status' => 'active', 'webhook_url' => 'https://customer.test/hooks']);
        $this->appSecret = $this->business->getPlainAppSecret();

        TelegramSetting::factory()->connected()->forBusiness($this->business)->create();
        $this->awa = TelegramSubscriber::factory()->forBusiness($this->business)->create([
            'chat_id' => 555001,
            'external_ref' => 'citizen-4521',
            'first_name' => 'Awa',
            'username' => 'awa_n',
        ]);
    }

    private function asApplication(): static
    {
        $token = $this->postJson('/api/v1/auth/token', [
            'app_id' => $this->business->app_id,
            'app_secret' => $this->appSecret,
        ])->json('data.access_token');

        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    private function template(array $attributes = []): TelegramTemplate
    {
        return TelegramTemplate::factory()->forBusiness($this->business)->create(array_merge([
            'name' => 'rdv',
            'body' => '<b>Bonjour {{ name }}</b>, votre rendez-vous est le {{ date }}.',
            'buttons' => [['text' => 'Dossier {{ ref }}', 'url' => 'https://aninf.ga/dossiers/{{ ref }}']],
        ], $attributes));
    }

    /* ---------------------------- Invitations --------------------------- */

    public function test_an_application_gets_an_invitation_link_for_one_of_its_users(): void
    {
        $this->asApplication()->postJson('/api/v1/app/telegram/invitations', ['external_ref' => 'citizen-9'])
            ->assertCreated()
            ->assertJsonPath('data.external_ref', 'citizen-9')
            ->assertJsonPath('data.link', fn (string $link) => (bool) preg_match('#^https://t\.me/AninfPushBot\?start=[A-Za-z0-9]{32}$#', $link));
    }

    public function test_an_application_checks_whether_a_user_subscribed(): void
    {
        $this->asApplication()->getJson('/api/v1/app/telegram/subscribers/citizen-4521')
            ->assertOk()
            ->assertJsonPath('data.subscribed', true);

        $this->asApplication()->getJson('/api/v1/app/telegram/subscribers/citizen-0')->assertNotFound();
    }

    /* ------------------------------ Sending ----------------------------- */

    public function test_a_message_is_composed_escaped_and_queued_on_the_telegram_queue(): void
    {
        Queue::fake();
        $template = $this->template();

        $this->asApplication()->postJson('/api/v1/app/messages/telegram', [
            'template_id' => $template->id,
            'external_ref' => 'citizen-4521',
            'variables' => ['name' => 'Awa <script>', 'date' => '12 octobre', 'ref' => 'D 1/2'],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.recipient.chat_id', 555001);

        $telegram = TelegramMessage::sole();
        // Values cannot break the markup.
        $this->assertSame('<b>Bonjour Awa &lt;script&gt;</b>, votre rendez-vous est le 12 octobre.', $telegram->text);
        $this->assertSame('HTML', $telegram->parse_mode);
        $this->assertSame(
            ['inline_keyboard' => [[['text' => 'Dossier D 1/2', 'url' => 'https://aninf.ga/dossiers/D%201%2F2']]]],
            $telegram->reply_markup
        );
        $this->assertSame('Awa (@awa_n)', $telegram->recipient_label);

        Queue::assertPushedOn('telegram', SendMessagesJob::class);
    }

    public function test_the_refused_cases(): void
    {
        Queue::fake();
        $template = $this->template();

        $this->asApplication()->postJson('/api/v1/app/messages/telegram', ['template_id' => $template->id, 'external_ref' => 'citizen-0', 'variables' => ['name' => 'a', 'date' => 'b', 'ref' => 'c']])
            ->assertNotFound();

        $this->asApplication()->postJson('/api/v1/app/messages/telegram', ['template_id' => $template->id, 'external_ref' => 'citizen-4521', 'variables' => ['name' => 'Awa']])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Missing template variables: date, ref');

        $this->awa->update(['status' => 'blocked']);
        $this->asApplication()->postJson('/api/v1/app/messages/telegram', ['template_id' => $template->id, 'external_ref' => 'citizen-4521', 'variables' => ['name' => 'a', 'date' => 'b', 'ref' => 'c']])
            ->assertStatus(422);

        $inactive = $this->template(['name' => 'off', 'is_active' => false]);
        $this->asApplication()->postJson('/api/v1/app/messages/telegram', ['template_id' => $inactive->id, 'chat_id' => 555001, 'variables' => ['name' => 'a', 'date' => 'b', 'ref' => 'c']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('template');

        TelegramSetting::query()->update(['test_status' => 'failed']);
        $this->asApplication()->postJson('/api/v1/app/messages/telegram', ['template_id' => $template->id, 'chat_id' => 555001, 'variables' => ['name' => 'a', 'date' => 'b', 'ref' => 'c']])
            ->assertStatus(503);

        Queue::assertNothingPushed();
    }

    /* ----------------------------- Delivery ----------------------------- */

    private function queued(): Message
    {
        Queue::fake();
        $template = $this->template();

        $id = $this->asApplication()->postJson('/api/v1/app/messages/telegram', [
            'template_id' => $template->id,
            'external_ref' => 'citizen-4521',
            'variables' => ['name' => 'Awa', 'date' => '12 octobre', 'ref' => 'D1'],
        ])->json('data.id');

        Queue::fake([SendWebhookNotification::class]);

        return Message::findOrFail($id);
    }

    public function test_the_job_sends_it_through_the_bot(): void
    {
        $message = $this->queued();
        $this->fakeTelegram();

        (new SendMessagesJob($message->id))->handle();

        $sent = $this->telegramCalls('sendMessage')->sole();
        $this->assertSame(555001, $sent['chat_id']);
        $this->assertSame('<b>Bonjour Awa</b>, votre rendez-vous est le 12 octobre.', $sent['text']);
        $this->assertSame('HTML', $sent['parse_mode']);
        $this->assertSame('https://aninf.ga/dossiers/D1', $sent['reply_markup']['inline_keyboard'][0][0]['url']);

        $message->refresh();
        $this->assertSame('sent', $message->status);
        $this->assertSame(42, $message->telegramMessage->provider_message_id);
        Queue::assertPushed(SendWebhookNotification::class);

        // Run again (a retry after a crash, say): never sent twice.
        (new SendMessagesJob($message->id))->handle();
        $this->assertCount(1, $this->telegramCalls('sendMessage'));
    }

    public function test_a_blocked_bot_fails_the_message_and_the_subscription(): void
    {
        $message = $this->queued();
        $this->fakeTelegram(['sendMessage' => fn () => $this->telegramError(403, 'Forbidden: bot was blocked by the user')]);

        (new SendMessagesJob($message->id))->handle();

        $this->assertSame('failed', $message->fresh()->status);
        $this->assertStringContainsString('bot was blocked by the user', $message->fresh()->error_message);
        $this->assertSame('blocked', $this->awa->fresh()->status);
    }

    public function test_telegram_flood_control_is_waited_for(): void
    {
        $message = $this->queued();
        $this->fakeTelegram(['sendMessage' => fn () => $this->telegramError(429, 'Too Many Requests: retry after 7', ['retry_after' => 7])]);

        (new SendMessagesJob($message->id))->handle();

        $this->assertSame('queued', $message->fresh()->status);
        $this->assertStringStartsWith('Waiting for the Telegram rate limit', $message->fresh()->error_message);
    }

    public function test_an_unreachable_telegram_is_retried(): void
    {
        $message = $this->queued();
        config(['services.telegram.api_url' => $this->telegramUrl]);
        Http::fake(['telegram.test/*' => Http::failedConnection('cURL error 7: Failed to connect to telegram.test port 443')]);

        try {
            (new SendMessagesJob($message->id))->handle();
            $this->fail('The job should throw so that the queue tries again.');
        } catch (TelegramException $e) {
            $this->assertStringStartsWith('Telegram could not be reached', $e->getMessage());
        }

        $this->assertSame('queued', $message->fresh()->status);
    }

    public function test_no_answer_once_sent_is_not_retried_blindly(): void
    {
        $message = $this->queued();
        config(['services.telegram.api_url' => $this->telegramUrl]);
        Http::fake(['telegram.test/*' => Http::failedConnection('cURL error 28: Operation timed out after 15000 milliseconds with 0 bytes received')]);

        (new SendMessagesJob($message->id))->handle();

        $this->assertSame('failed', $message->fresh()->status);
        $this->assertStringContainsString('Telegram may have delivered it', $message->fresh()->error_message);
    }

    public function test_the_status_endpoint_and_the_webhook_carry_the_telegram_recipient(): void
    {
        $message = $this->queued();
        $this->fakeTelegram();
        (new SendMessagesJob($message->id))->handle();

        $this->asApplication()->getJson("/api/v1/app/messages/{$message->message_id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.telegram.external_ref', 'citizen-4521')
            ->assertJsonPath('data.telegram.telegram_message_id', 42);

        Http::fake(['customer.test/*' => Http::response('ok')]);
        (new SendWebhookNotification($message->id, SendWebhookNotification::EVENT_SENT))->handle();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://customer.test/hooks'
            && $request['data']['message_type'] === 'telegram'
            && $request['data']['recipient'] === 'citizen-4521');
    }
}
