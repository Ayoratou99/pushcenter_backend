<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\TelegramInvitation;
use App\Models\TelegramSetting;
use App\Models\TelegramSubscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesTelegram;
use Tests\TestCase;

/**
 * The Telegram tab: the bot token, who started the bot, invitation links.
 */
class TelegramSettingsTest extends TestCase
{
    use FakesTelegram;
    use RefreshDatabase;

    private const TOKEN = '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw1';

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create(['name' => 'Guichet ANINF']);
        $this->actingAsUser($this->globalManager());
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/businesses/{$this->business->id}/telegram-settings{$suffix}";
    }

    private function connected(array $attributes = []): TelegramSetting
    {
        return TelegramSetting::factory()->connected()->forBusiness($this->business)->create($attributes);
    }

    public function test_the_token_is_stored_encrypted_and_never_returned(): void
    {
        $response = $this->putJson($this->url(), ['bot_token' => self::TOKEN, 'welcome_message' => 'Bienvenue !'])
            ->assertOk()
            ->assertJsonPath('data.bot_token_hint', 'saw1')
            ->assertJsonPath('data.has_token', true)
            ->assertJsonPath('data.welcome_message', 'Bienvenue !');

        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        $this->assertNotSame(self::TOKEN, DB::table('telegram_settings')->value('bot_token'));
        $this->assertSame(self::TOKEN, TelegramSetting::first()->bot_token);
    }

    public function test_something_that_is_not_a_bot_token_is_refused(): void
    {
        $this->putJson($this->url(), ['bot_token' => 'my-secret'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('bot_token');
    }

    public function test_another_bot_asks_its_subscribers_to_start_it_again(): void
    {
        $setting = $this->connected(['bot_token' => self::TOKEN]);
        $awa = TelegramSubscriber::factory()->forBusiness($this->business)->create(['external_ref' => 'citizen-1']);

        // The same bot with a token revoked in @BotFather: nothing changes.
        $this->putJson($this->url(), ['bot_token' => '123456789:BBHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw2'])->assertOk()
            ->assertJsonPath('data.bot_username', 'AninfPushBot')
            ->assertJsonPath('data.test_status', 'not_tested');
        $this->assertSame('active', $awa->fresh()->status);

        // Another bot: nobody started it yet.
        $this->putJson($this->url(), ['bot_token' => '987654321:CCHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw3'])->assertOk()
            ->assertJsonPath('data.bot_username', null)
            ->assertJsonPath('data.subscribers', ['active' => 0, 'blocked' => 1]);
        $this->assertSame('blocked', $awa->fresh()->status);
        $this->assertSame('citizen-1', $awa->fresh()->external_ref);
        $this->assertSame(0, $setting->fresh()->update_offset);
    }

    public function test_the_test_reads_the_bot_identity(): void
    {
        $this->fakeTelegram();
        TelegramSetting::create(['business_id' => $this->business->id, 'bot_token' => self::TOKEN]);

        $this->postJson($this->url('/test'))
            ->assertOk()
            ->assertJsonPath('message', 'Connected to @AninfPushBot')
            ->assertJsonPath('data.bot_username', 'AninfPushBot')
            ->assertJsonPath('data.bot_link', 'https://t.me/AninfPushBot')
            ->assertJsonPath('data.test_status', 'success');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://telegram.test/bot' . self::TOKEN . '/getMe');
    }

    public function test_a_refused_token_is_explained_without_revealing_it(): void
    {
        $this->fakeTelegram(['getMe' => fn () => $this->telegramError(401, 'Unauthorized')]);
        TelegramSetting::create(['business_id' => $this->business->id, 'bot_token' => self::TOKEN]);

        $this->postJson($this->url('/test'))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Telegram rejected the bot token: Unauthorized');

        $this->assertSame('failed', TelegramSetting::first()->test_status);
    }

    public function test_the_token_never_leaks_through_an_error_message(): void
    {
        config(['services.telegram.api_url' => $this->telegramUrl]);
        Http::fake(['*' => Http::failedConnection('cURL error 7: Failed to connect for https://telegram.test/bot' . self::TOKEN . '/getMe')]);
        TelegramSetting::create(['business_id' => $this->business->id, 'bot_token' => self::TOKEN]);

        $response = $this->postJson($this->url('/test'))->assertStatus(502);

        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        $this->assertStringNotContainsString(self::TOKEN, (string) TelegramSetting::first()->test_error);
        $this->assertStringContainsString('bot***', (string) TelegramSetting::first()->test_error);
    }

    public function test_a_bot_already_used_through_a_webhook_is_flagged(): void
    {
        $this->fakeTelegram(['getWebhookInfo' => fn () => $this->telegramOk(['url' => 'https://other-app.example/hook'])]);
        TelegramSetting::create(['business_id' => $this->business->id, 'bot_token' => self::TOKEN]);

        $this->postJson($this->url('/test'))
            ->assertStatus(422)
            ->assertJsonPath('errors.settings.test_status', 'failed');

        $this->assertStringContainsString('other-app.example', TelegramSetting::first()->test_error);
    }

    public function test_starting_the_bot_with_an_invitation_ties_the_chat_to_the_reference(): void
    {
        $setting = $this->connected(['welcome_message' => 'Bienvenue au Guichet !']);
        $invitation = TelegramInvitation::create([
            'business_id' => $this->business->id,
            'token' => 'aBcDeFgHiJkLmNoPqRsTuVwXyZ012345',
            'external_ref' => 'citizen-4521',
            'expires_at' => now()->addDay(),
        ]);

        $this->fakeTelegram([
            'getUpdates' => fn () => $this->telegramOk([
                $this->telegramStartUpdate(900, 555001, '/start aBcDeFgHiJkLmNoPqRsTuVwXyZ012345'),
                $this->telegramStartUpdate(901, 555002),
            ]),
        ]);

        $this->postJson($this->url('/poll'))
            ->assertOk()
            ->assertJsonPath('data', ['updates' => 2, 'subscribed' => 2, 'blocked' => 0]);

        $awa = TelegramSubscriber::where('chat_id', 555001)->sole();
        $this->assertSame('citizen-4521', $awa->external_ref);
        $this->assertSame('awa_n', $awa->username);
        $this->assertSame('active', $awa->status);
        $this->assertNotNull($invitation->fresh()->used_at);
        $this->assertSame($awa->id, $invitation->fresh()->telegram_subscriber_id);

        // The generic link subscribes without a reference.
        $this->assertNull(TelegramSubscriber::where('chat_id', 555002)->sole()->external_ref);

        $this->assertSame(901, $setting->fresh()->update_offset);
        $welcome = $this->telegramCalls('sendMessage')->first();
        $this->assertSame(555001, $welcome['chat_id']);
        $this->assertSame('Bienvenue au Guichet !', $welcome['text']);

        // The next poll asks for what follows.
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/getUpdates') && $request['offset'] === 0);
    }

    public function test_an_invitation_works_once_and_not_after_it_expired(): void
    {
        $this->connected();
        TelegramInvitation::create(['business_id' => $this->business->id, 'token' => 'usedTokenUsedTokenUsedTokenUsedT', 'external_ref' => 'a', 'used_at' => now()]);
        TelegramInvitation::create(['business_id' => $this->business->id, 'token' => 'oldTokenOldTokenOldTokenOldToken', 'external_ref' => 'b', 'expires_at' => now()->subDay()]);

        $this->fakeTelegram([
            'getUpdates' => fn () => $this->telegramOk([
                $this->telegramStartUpdate(10, 777001, '/start usedTokenUsedTokenUsedTokenUsedT'),
                $this->telegramStartUpdate(11, 777002, '/start oldTokenOldTokenOldTokenOldToken'),
            ]),
        ]);

        $this->postJson($this->url('/poll'))->assertOk();

        $this->assertNull(TelegramSubscriber::where('chat_id', 777001)->sole()->external_ref);
        $this->assertNull(TelegramSubscriber::where('chat_id', 777002)->sole()->external_ref);
    }

    public function test_blocking_the_bot_or_sending_stop_unsubscribes(): void
    {
        $this->connected();
        TelegramSubscriber::factory()->forBusiness($this->business)->create(['chat_id' => 888001]);
        TelegramSubscriber::factory()->forBusiness($this->business)->create(['chat_id' => 888002]);

        $this->fakeTelegram([
            'getUpdates' => fn () => $this->telegramOk([
                [
                    'update_id' => 20,
                    'my_chat_member' => [
                        'chat' => ['id' => 888001, 'type' => 'private'],
                        'from' => ['id' => 888001],
                        'old_chat_member' => ['status' => 'member'],
                        'new_chat_member' => ['status' => 'kicked'],
                    ],
                ],
                $this->telegramStartUpdate(21, 888002, '/stop'),
            ]),
        ]);

        $this->postJson($this->url('/poll'))->assertOk()->assertJsonPath('data.blocked', 2);

        $this->assertSame(['blocked', 'blocked'], TelegramSubscriber::orderBy('chat_id')->pluck('status')->all());
    }

    public function test_group_chats_are_ignored(): void
    {
        $this->connected();
        $this->fakeTelegram([
            'getUpdates' => fn () => $this->telegramOk([
                $this->telegramStartUpdate(30, -100123, '/start', ['message' => ['chat' => ['type' => 'group']]]),
            ]),
        ]);

        $this->postJson($this->url('/poll'))->assertOk()->assertJsonPath('data.subscribed', 0);

        $this->assertDatabaseCount('telegram_subscribers', 0);
    }

    public function test_the_scheduled_poll_goes_through_every_connected_bot(): void
    {
        $this->connected();
        $this->fakeTelegram(['getUpdates' => fn () => $this->telegramOk([$this->telegramStartUpdate(40, 999001)])]);

        $this->artisan('telegram:poll')->assertSuccessful();

        $this->assertDatabaseHas('telegram_subscribers', ['chat_id' => 999001, 'business_id' => $this->business->id]);
    }

    public function test_the_console_creates_invitation_links(): void
    {
        $this->connected();

        $this->postJson("/api/v1/businesses/{$this->business->id}/telegram-invitations", ['external_ref' => 'citizen-7', 'label' => 'Awa'])
            ->assertCreated()
            ->assertJsonPath('data.external_ref', 'citizen-7')
            ->assertJsonPath('data.link', fn (string $link) => str_starts_with($link, 'https://t.me/AninfPushBot?start='));

        $this->getJson("/api/v1/businesses/{$this->business->id}/telegram-invitations")
            ->assertOk()
            ->assertJsonPath('data.total', 1);
    }

    public function test_subscribers_are_listed_and_can_be_forgotten(): void
    {
        $this->connected();
        $subscriber = TelegramSubscriber::factory()->forBusiness($this->business)->create(['first_name' => 'Awa']);
        TelegramSubscriber::factory()->forBusiness($this->business)->create(['status' => 'blocked']);

        $this->getJson("/api/v1/businesses/{$this->business->id}/telegram-subscribers?status=active")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.first_name', 'Awa');

        $this->deleteJson("/api/v1/businesses/{$this->business->id}/telegram-subscribers/{$subscriber->id}")->assertOk();
        $this->assertDatabaseMissing('telegram_subscribers', ['id' => $subscriber->id]);
    }

    public function test_a_restricted_manager_only_reaches_its_own_bot(): void
    {
        $other = Business::factory()->create();
        TelegramSetting::factory()->connected()->forBusiness($other)->create();
        $this->actingAsUser($this->restrictedManager([$this->business->id]));

        $this->getJson("/api/v1/businesses/{$other->id}/telegram-settings")->assertForbidden();
        $this->postJson("/api/v1/businesses/{$other->id}/telegram-invitations")->assertForbidden();
        $this->getJson("/api/v1/businesses/{$other->id}/telegram-subscribers")->assertForbidden();
    }
}
