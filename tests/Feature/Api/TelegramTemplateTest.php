<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\EmailTemplate;
use App\Models\SmsTemplate;
use App\Models\TelegramTemplate;
use App\Models\WhatsappTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Telegram templates, and duplication of every template type.
 */
class TelegramTemplateTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->actingAsUser($this->globalManager());
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'business_id' => $this->business->id,
            'name' => 'Rendez-vous confirmé',
            'category' => 'notification',
            'body' => "<b>Bonjour {{ name }}</b>,\nvotre rendez-vous est le <i>{{ date }}</i>.",
            'parse_mode' => 'HTML',
            'buttons' => [['text' => 'Voir le dossier', 'url' => 'https://aninf.ga/dossiers/{{ ref }}']],
            'sample_data' => ['name' => 'Awa', 'date' => '12 octobre', 'ref' => 'D-1'],
        ], $overrides);
    }

    public function test_a_template_is_created_with_its_variables(): void
    {
        $this->postJson('/api/v1/telegram-templates', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.variables', ['name', 'date', 'ref'])
            ->assertJsonPath('data.status', 'active');
    }

    public function test_tags_telegram_refuses_are_caught(): void
    {
        $this->postJson('/api/v1/telegram-templates', $this->payload(['body' => '<div>Bonjour</div> <br>']))
            ->assertStatus(422)
            ->assertJsonPath('errors.body.0', fn (string $message) => str_contains($message, '<div>') && str_contains($message, '<br>'));

        $this->postJson('/api/v1/telegram-templates', $this->payload(['body' => 'Prix < 10 & remise']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        $this->postJson('/api/v1/telegram-templates', $this->payload(['body' => '<b>Bonjour <i>Awa</b></i>']))
            ->assertStatus(422)
            ->assertJsonPath('errors.body.0', '</b> closes a tag that is not the last one opened.');

        $this->postJson('/api/v1/telegram-templates', $this->payload(['body' => '<b>Bonjour']))
            ->assertStatus(422)
            ->assertJsonPath('errors.body.0', 'Close <b>.');

        $this->postJson('/api/v1/telegram-templates', $this->payload(['body' => '<span>caché</span> <a>lien</a>']))
            ->assertStatus(422)
            ->assertJsonCount(1, 'errors.body');

        // Plain text has no markup rules.
        $this->postJson('/api/v1/telegram-templates', $this->payload(['name' => 'Texte', 'body' => 'Prix < 10 & remise', 'parse_mode' => 'plain']))
            ->assertCreated();
    }

    public function test_every_telegram_format_is_accepted(): void
    {
        $body = "<b>gras</b> <strong>gras</strong> <i>it</i> <em>it</em> <u>s</u> <ins>s</ins> <s>b</s> <del>b</del>\n"
            . '<span class="tg-spoiler">caché</span> <tg-spoiler>caché</tg-spoiler> <a href="https://aninf.ga">lien</a>'
            . "\n<code>code</code> <pre><code class=\"language-php\">echo 1;</code></pre> <blockquote expandable>cite</blockquote> &lt;3 &amp; &#8364;";

        $this->postJson('/api/v1/telegram-templates', $this->payload(['body' => $body]))->assertCreated();
    }

    public function test_button_links_must_be_urls(): void
    {
        $this->postJson('/api/v1/telegram-templates', $this->payload(['buttons' => [['text' => 'Voir', 'url' => 'aninf.ga']]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('buttons');
    }

    public function test_a_telegram_template_can_be_edited(): void
    {
        $template = TelegramTemplate::factory()->forBusiness($this->business)->create();

        $this->putJson("/api/v1/telegram-templates/{$template->id}", ['body' => 'Bonjour {{ prenom }} !'])
            ->assertOk()
            ->assertJsonPath('data.variables', ['prenom']);
    }

    public function test_every_template_type_can_be_duplicated(): void
    {
        $templates = [
            'email' => EmailTemplate::factory()->forBusiness($this->business)->create(['name' => 'Bienvenue']),
            'sms' => SmsTemplate::factory()->create(['business_id' => $this->business->id, 'name' => 'Code']),
            'whatsapp' => WhatsappTemplate::factory()->forBusiness($this->business)->approved()->create([
                'name' => 'confirmation',
                'provider_template_id' => 55,
                'provider_template_name' => 'confirmation_biz3_1',
            ]),
            'telegram' => TelegramTemplate::factory()->forBusiness($this->business)->create(['name' => 'Rappel']),
        ];

        foreach ($templates as $type => $template) {
            $copy = $this->postJson("/api/v1/templates/{$type}/{$template->id}/duplicate")
                ->assertCreated()
                ->assertJsonPath('data.type', $type)
                ->assertJsonPath('data.is_active', false)
                ->json('data');

            $this->assertNotSame($template->id, $copy['id']);
            $this->assertNotSame($template->name, $copy['name']);
            $this->assertSame($template->id, $copy['metadata']['duplicated_from']);
        }

        // A WhatsApp copy is a fresh draft: nothing of the approved original on AyosPush.
        $copy = WhatsappTemplate::where('name', 'confirmation_copy')->sole();
        $this->assertSame('draft', $copy->status);
        $this->assertNull($copy->provider_template_id);
        $this->assertNull($copy->provider_template_name);

        // Copies of copies keep names Meta accepts.
        $this->postJson("/api/v1/templates/whatsapp/{$templates['whatsapp']->id}/duplicate")
            ->assertCreated()
            ->assertJsonPath('data.name', 'confirmation_copy_2');
        $this->postJson("/api/v1/templates/telegram/{$templates['telegram']->id}/duplicate")
            ->assertCreated()
            ->assertJsonPath('data.name', 'Rappel copy (2)');
    }

    public function test_a_template_of_another_application_cannot_be_duplicated(): void
    {
        $foreign = TelegramTemplate::factory()->create();
        $this->actingAsUser($this->restrictedManager([$this->business->id]));

        $this->postJson("/api/v1/templates/telegram/{$foreign->id}/duplicate")->assertForbidden();
    }

    public function test_telegram_templates_move_between_applications(): void
    {
        $template = TelegramTemplate::factory()->forBusiness($this->business)->create(['name' => 'Rappel']);
        $target = Business::factory()->create();

        $document = $this->getJson("/api/v1/templates/telegram/{$template->id}/export?download=0")
            ->assertOk()
            ->json('data.content');

        $this->postJson('/api/v1/templates/import', [
            'business_id' => $target->id,
            'content' => $document,
        ])->assertCreated();

        $imported = TelegramTemplate::where('business_id', $target->id)->sole();
        $this->assertSame($template->body, $imported->body);
        $this->assertFalse($imported->is_active);
    }
}
