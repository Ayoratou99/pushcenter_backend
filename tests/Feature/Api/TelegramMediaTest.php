<?php

namespace Tests\Feature\Api;

use App\Jobs\SendMessagesJob;
use App\Jobs\SendWebhookNotification;
use App\Models\Business;
use App\Models\Message;
use App\Models\TelegramSetting;
use App\Models\TelegramSubscriber;
use App\Models\TelegramTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\FakesTelegram;
use Tests\TestCase;

/**
 * Telegram attachments: a file kept with the template (uploaded to Telegram
 * once per bot, then reused), or a link the application gives with each
 * message, which Telegram downloads itself.
 */
class TelegramMediaTest extends TestCase
{
    use FakesTelegram;
    use RefreshDatabase;

    private Business $business;

    private string $appSecret;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->business = Business::factory()->create(['status' => 'active']);
        $this->appSecret = $this->business->getPlainAppSecret();
        TelegramSetting::factory()->connected()->forBusiness($this->business)->create();
        TelegramSubscriber::factory()->forBusiness($this->business)->create(['chat_id' => 555001, 'external_ref' => 'citizen-4521']);

        $this->manager = $this->globalManager();
        $this->actingAsUser($this->manager);
    }

    private function template(array $attributes = []): TelegramTemplate
    {
        return TelegramTemplate::factory()->forBusiness($this->business)->create(array_merge([
            'name' => 'guide',
            'body' => 'Voici le guide, {{ name }}.',
            'variables' => ['name'],
        ], $attributes));
    }

    private function attach(TelegramTemplate $template, UploadedFile $file, ?string $type = null): TestResponse
    {
        $this->actingAsUser($this->manager);

        return $this->post(
            "/api/v1/telegram-templates/{$template->id}/media",
            array_filter(['file' => $file, 'type' => $type]),
            ['Accept' => 'application/json']
        );
    }

    private function asApplication(): static
    {
        $token = $this->postJson('/api/v1/auth/token', [
            'app_id' => $this->business->app_id,
            'app_secret' => $this->appSecret,
        ])->json('data.access_token');

        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    /** Queues a message through the application API, then lets the job send it. */
    private function sendThroughTheJob(array $payload): Message
    {
        Queue::fake();
        $id = $this->asApplication()->postJson('/api/v1/app/messages/telegram', $payload)->assertCreated()->json('data.id');

        Queue::fake([SendWebhookNotification::class]);
        (new SendMessagesJob($id))->handle();

        return Message::findOrFail($id);
    }

    /* ------------------------------ The file ----------------------------- */

    public function test_a_photo_is_kept_with_the_template_and_its_path_never_shown(): void
    {
        $template = $this->template();

        $this->attach($template, UploadedFile::fake()->image('banniere.png', 1200, 630), 'photo')
            ->assertOk()
            ->assertJsonPath('data.media_type', 'photo')
            ->assertJsonPath('data.media_source', 'file')
            ->assertJsonPath('data.media_name', 'banniere.png')
            ->assertJsonPath('data.has_media_file', true)
            ->assertJsonMissingPath('data.media_path')
            ->assertJsonMissingPath('data.media_file_ids');

        $path = $template->fresh()->media_path;
        $this->assertStringStartsWith("telegram-media/{$this->business->id}/", $path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_a_new_file_replaces_the_old_one_and_forgets_telegram_ids(): void
    {
        $template = $this->template();
        $this->attach($template, UploadedFile::fake()->image('a.png'), 'photo')->assertOk();
        $old = $template->fresh()->media_path;
        $template->fresh()->forceFill(['media_file_ids' => ['bot123456789' => 'photo-large']])->save();

        $this->attach($template, UploadedFile::fake()->create('guide.pdf', 200, 'application/pdf'), 'document')
            ->assertOk()
            ->assertJsonPath('data.media_type', 'document');

        Storage::disk('local')->assertMissing($old);
        $this->assertNull($template->fresh()->media_file_ids);
    }

    public function test_what_telegram_would_refuse_is_caught_at_upload(): void
    {
        $template = $this->template();

        $this->attach($template, UploadedFile::fake()->create('guide.pdf', 100, 'application/pdf'), 'photo')
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', 'A photo is a JPEG, PNG or WebP image.');

        $this->attach($template, UploadedFile::fake()->image('big.jpg')->size(11000), 'photo')
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', 'Telegram takes photos up to 10 MB.');

        $this->attach($template, UploadedFile::fake()->image('strip.png', 2100, 100), 'photo')
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', 'Telegram refuses photos more than 20 times longer than wide.');

        $this->attach($template, UploadedFile::fake()->create('setup.exe', 10, 'application/x-msdownload'), 'document')
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        // Neither a type nor an attachment on the template.
        $this->attach($template, UploadedFile::fake()->image('a.png'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');

        Storage::disk('local')->assertDirectoryEmpty('/');
    }

    public function test_with_a_file_the_text_is_a_caption(): void
    {
        $long = $this->template(['body' => str_repeat('a', 1500)]);
        $this->attach($long, UploadedFile::fake()->image('a.png'), 'photo')
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        $base = ['business_id' => $this->business->id, 'category' => 'notification'];

        $this->postJson('/api/v1/telegram-templates', $base + ['name' => 'promo', 'body' => str_repeat('a', 1100), 'media_type' => 'photo', 'media_source' => 'url'])
            ->assertStatus(422)
            ->assertJsonPath('errors.body.0', 'With a photo, video or document the text is a caption: 1024 characters at most.');

        // A file may go without text; a message may not.
        $this->postJson('/api/v1/telegram-templates', $base + ['name' => 'banner', 'media_type' => 'photo', 'media_source' => 'file'])
            ->assertCreated()
            ->assertJsonPath('data.has_media_file', false);

        $this->postJson('/api/v1/telegram-templates', $base + ['name' => 'empty'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        $this->postJson('/api/v1/telegram-templates', $base + ['name' => 'nosource', 'body' => 'x', 'media_type' => 'photo'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('media_source');
    }

    public function test_the_console_downloads_the_file_of_its_own_applications_only(): void
    {
        $template = $this->template();
        $this->attach($template, UploadedFile::fake()->create('guide.pdf', 50, 'application/pdf'), 'document')->assertOk();

        $response = $this->get("/api/v1/telegram-templates/{$template->id}/media")->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));

        $this->actingAsUser($this->restrictedManager([Business::factory()->create()->id]));
        $this->getJson("/api/v1/telegram-templates/{$template->id}/media")->assertForbidden();
        $this->post("/api/v1/telegram-templates/{$template->id}/media", ['file' => UploadedFile::fake()->image('x.png'), 'type' => 'photo'], ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    public function test_removing_the_attachment_turns_it_back_into_a_text_message(): void
    {
        $template = $this->template();
        $this->attach($template, UploadedFile::fake()->image('a.png'), 'photo')->assertOk();
        $path = $template->fresh()->media_path;

        $this->deleteJson("/api/v1/telegram-templates/{$template->id}/media")
            ->assertOk()
            ->assertJsonPath('data.media_type', null)
            ->assertJsonPath('data.has_media_file', false);

        Storage::disk('local')->assertMissing($path);

        // A template made of its file alone would be left empty.
        $banner = $this->template(['name' => 'banner', 'body' => '', 'variables' => []]);
        $this->attach($banner, UploadedFile::fake()->image('b.png'), 'photo')->assertOk();
        $this->deleteJson("/api/v1/telegram-templates/{$banner->id}/media")->assertStatus(422);
    }

    public function test_changing_or_deleting_the_template_drops_its_file(): void
    {
        $template = $this->template();
        $this->attach($template, UploadedFile::fake()->image('a.png'), 'photo')->assertOk();
        $path = $template->fresh()->media_path;

        // Now a link given with each message: the kept file has no use.
        $this->putJson("/api/v1/telegram-templates/{$template->id}", ['media_source' => 'url'])
            ->assertOk()
            ->assertJsonPath('data.media_source', 'url')
            ->assertJsonPath('data.has_media_file', false);
        Storage::disk('local')->assertMissing($path);

        $other = $this->template(['name' => 'other']);
        $this->attach($other, UploadedFile::fake()->image('b.png'), 'photo')->assertOk();
        $otherPath = $other->fresh()->media_path;

        $this->deleteJson("/api/v1/telegram-templates/{$other->id}")->assertOk();
        Storage::disk('local')->assertMissing($otherPath);
    }

    public function test_a_copy_gets_its_own_file(): void
    {
        $template = $this->template();
        $this->attach($template, UploadedFile::fake()->image('a.png', 400, 300), 'photo')->assertOk();

        $this->postJson("/api/v1/templates/telegram/{$template->id}/duplicate")
            ->assertCreated()
            ->assertJsonPath('data.media_type', 'photo')
            ->assertJsonPath('data.has_media_file', true);

        $original = $template->fresh()->media_path;
        $copy = TelegramTemplate::where('name', 'guide copy')->sole()->media_path;

        $this->assertNotSame($original, $copy);
        $this->assertSame(Storage::disk('local')->get($original), Storage::disk('local')->get($copy));
    }

    /* ------------------------------ Sending ------------------------------ */

    public function test_the_kept_photo_is_uploaded_once_then_sent_by_its_telegram_id(): void
    {
        $template = $this->template([
            'body' => '<b>Bonjour {{ name }}</b>, voici le guide.',
            'buttons' => [['text' => 'Site', 'url' => 'https://aninf.ga']],
        ]);
        $this->attach($template, UploadedFile::fake()->image('guide.png', 800, 600), 'photo')->assertOk();
        $contents = Storage::disk('local')->get($template->fresh()->media_path);
        $this->fakeTelegram();

        $message = $this->sendThroughTheJob(['template_id' => $template->id, 'external_ref' => 'citizen-4521', 'variables' => ['name' => 'Awa']]);

        $this->assertSame('sent', $message->status);
        $this->assertSame(43, $message->telegramMessage->provider_message_id);

        $upload = $this->telegramCalls('sendPhoto')->sole();
        $this->assertSame('555001', $this->telegramParam($upload, 'chat_id'));
        $this->assertSame('<b>Bonjour Awa</b>, voici le guide.', $this->telegramParam($upload, 'caption'));
        $this->assertSame('HTML', $this->telegramParam($upload, 'parse_mode'));
        $this->assertSame('https://aninf.ga', json_decode($this->telegramParam($upload, 'reply_markup'), true)['inline_keyboard'][0][0]['url']);
        $this->assertSame('guide.png', $this->telegramPart($upload, 'photo')['filename']);
        $this->assertSame($contents, $this->telegramPart($upload, 'photo')['contents']);

        // Telegram's id of the largest size, for this bot.
        $this->assertSame(['bot123456789' => 'photo-large'], $template->fresh()->media_file_ids);

        $this->sendThroughTheJob(['template_id' => $template->id, 'external_ref' => 'citizen-4521', 'variables' => ['name' => 'Awa']]);

        $again = $this->telegramCalls('sendPhoto')->last();
        $this->assertNull($this->telegramPart($again, 'photo'));
        $this->assertSame('photo-large', $again['photo']);
    }

    public function test_a_file_id_telegram_forgot_is_replaced_by_a_new_upload(): void
    {
        $template = $this->template();
        $this->attach($template, UploadedFile::fake()->image('a.png'), 'photo')->assertOk();
        $template->fresh()->forceFill(['media_file_ids' => ['bot123456789' => 'stale-id']])->save();

        $this->fakeTelegram([
            'sendPhoto' => fn (Request $request) => $this->telegramPart($request, 'photo') === null
                ? $this->telegramError(400, 'Bad Request: wrong file identifier/HTTP URL specified')
                : $this->telegramOk(['message_id' => 50, 'photo' => [['file_id' => 'fresh-id']]]),
        ]);

        $message = $this->sendThroughTheJob(['template_id' => $template->id, 'external_ref' => 'citizen-4521', 'variables' => ['name' => 'Awa']]);

        $this->assertSame('sent', $message->status);
        $this->assertCount(2, $this->telegramCalls('sendPhoto'));
        $this->assertSame(['bot123456789' => 'fresh-id'], $template->fresh()->media_file_ids);
    }

    public function test_a_file_gone_after_queueing_fails_the_message_with_the_reason(): void
    {
        $template = $this->template();
        $this->attach($template, UploadedFile::fake()->image('a.png'), 'photo')->assertOk();
        $this->fakeTelegram();

        Queue::fake();
        $id = $this->asApplication()->postJson('/api/v1/app/messages/telegram', [
            'template_id' => $template->id, 'external_ref' => 'citizen-4521', 'variables' => ['name' => 'Awa'],
        ])->assertCreated()->json('data.id');

        Storage::disk('local')->delete($template->fresh()->media_path);
        Queue::fake([SendWebhookNotification::class]);
        (new SendMessagesJob($id))->handle();

        $this->assertSame('failed', Message::find($id)->status);
        $this->assertStringContainsString('The file of the Telegram template is missing', Message::find($id)->error_message);
        $this->assertCount(0, $this->telegramCalls('sendPhoto'));
    }

    public function test_the_application_gives_the_file_of_each_message_as_a_link(): void
    {
        $template = $this->template([
            'name' => 'facture',
            'body' => 'Votre facture {{ numero }}.',
            'variables' => ['numero'],
            'media_type' => 'document',
            'media_source' => 'url',
        ]);
        $payload = ['template_id' => $template->id, 'external_ref' => 'citizen-4521', 'variables' => ['numero' => 'F-1']];
        $this->fakeTelegram();
        Queue::fake();

        $this->asApplication()->postJson('/api/v1/app/messages/telegram', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('media_url');

        $this->asApplication()->postJson('/api/v1/app/messages/telegram', $payload + ['media_url' => 'ftp://files.example.com/F-1.pdf'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('media_url');

        $this->asApplication()->getJson('/api/v1/app/templates/telegram')
            ->assertOk()
            ->assertJsonPath('data.0.media_type', 'document')
            ->assertJsonPath('data.0.needs_media_url', true);

        $message = $this->sendThroughTheJob($payload + ['media_url' => 'https://files.example.com/F-1.pdf']);

        $this->assertSame('sent', $message->status);
        $sent = $this->telegramCalls('sendDocument')->sole();
        $this->assertSame('https://files.example.com/F-1.pdf', $sent['document']);
        $this->assertSame('Votre facture F-1.', $sent['caption']);
        $this->assertSame(555001, $sent['chat_id']);

        $this->asApplication()->getJson("/api/v1/app/messages/{$message->message_id}")
            ->assertJsonPath('data.telegram.media', ['type' => 'document', 'source' => 'url', 'url' => 'https://files.example.com/F-1.pdf']);
    }

    public function test_a_link_telegram_cannot_fetch_fails_the_message_with_a_hint(): void
    {
        $template = $this->template(['media_type' => 'photo', 'media_source' => 'url']);
        $this->fakeTelegram(['sendPhoto' => fn () => $this->telegramError(400, 'Bad Request: failed to get HTTP URL content')]);

        $message = $this->sendThroughTheJob([
            'template_id' => $template->id, 'external_ref' => 'citizen-4521', 'variables' => ['name' => 'Awa'],
            'media_url' => 'https://intranet.example.com/private.jpg',
        ]);

        $this->assertSame('failed', $message->status);
        $this->assertStringContainsString('failed to get HTTP URL content', $message->error_message);
        $this->assertStringContainsString('Telegram could not use the file link', $message->error_message);
    }

    public function test_the_application_is_told_what_does_not_fit(): void
    {
        Queue::fake();

        // A text template takes no link.
        $text = $this->template(['name' => 'texte']);
        $this->asApplication()->postJson('/api/v1/app/messages/telegram', [
            'template_id' => $text->id, 'external_ref' => 'citizen-4521', 'variables' => ['name' => 'Awa'],
            'media_url' => 'https://files.example.com/x.pdf',
        ])->assertStatus(422)->assertJsonValidationErrors('media_url');

        // A kept file that was never attached.
        $noFile = $this->template(['name' => 'sans-fichier', 'media_type' => 'photo', 'media_source' => 'file']);
        $this->asApplication()->postJson('/api/v1/app/messages/telegram', [
            'template_id' => $noFile->id, 'external_ref' => 'citizen-4521', 'variables' => ['name' => 'Awa'],
        ])->assertStatus(422)->assertJsonPath('message', 'The file of this template is missing: attach it again in the console.');

        // Values that make the caption too long.
        $link = $this->template(['name' => 'lien', 'media_type' => 'photo', 'media_source' => 'url']);
        $this->asApplication()->postJson('/api/v1/app/messages/telegram', [
            'template_id' => $link->id, 'external_ref' => 'citizen-4521', 'variables' => ['name' => str_repeat('a', 1100)],
            'media_url' => 'https://files.example.com/x.jpg',
        ])->assertStatus(422)->assertJsonPath('message', 'The caption exceeds the 1024 characters Telegram allows with a file.');

        Queue::assertNothingPushed();
    }
}
