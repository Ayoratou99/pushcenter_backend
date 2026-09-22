<?php

namespace Tests\Feature\Api;

use App\Jobs\SendMessagesJob;
use App\Models\Business;
use App\Models\Message;
use App\Models\WhatsAppMessage;
use App\Models\WhatsappSetting;
use App\Models\WhatsappTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * app_id + app_secret -> Bearer -> approved WhatsApp template -> queued on the
 * `whatsapp` queue -> SendMessagesJob hands it to AyosPush.
 */
class ApplicationWhatsappApiTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private string $appSecret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create(['status' => 'active']);
        $this->appSecret = $this->business->getPlainAppSecret();

        WhatsappSetting::factory()->connected()->forBusiness($this->business)->create();
    }

    private function asApplication(): static
    {
        $token = $this->postJson('/api/v1/auth/token', [
            'app_id' => $this->business->app_id,
            'app_secret' => $this->appSecret,
        ])->json('data.access_token');

        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    private function approvedTemplate(array $attributes = []): WhatsappTemplate
    {
        return WhatsappTemplate::factory()->forBusiness($this->business)->create(array_merge([
            'name' => 'order_confirmation',
            'language' => 'fr',
            'category' => 'UTILITY',
            'body' => 'Bonjour {{1}}, votre commande {{2}} est confirmée.',
            'buttons' => null,
            'status' => 'approved',
            'is_active' => true,
            'provider' => 'ayospush',
            'provider_template_id' => 55,
            'provider_template_name' => 'order_confirmation_biz3_0922101530',
        ], $attributes));
    }

    private function send(array $payload)
    {
        return $this->asApplication()->postJson('/api/v1/app/messages/whatsapp', $payload);
    }

    public function test_an_approved_template_is_queued_on_the_whatsapp_queue(): void
    {
        Queue::fake();
        $template = $this->approvedTemplate();

        $response = $this->send([
            'template_id' => $template->id,
            'recipient_number' => '+241 77 12 34 56',
            'recipient_name' => 'Awa',
            'variables' => ['1' => 'Awa', '2' => 'CMD-001'],
            'campaign_id' => 'rentree-2026',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.message_type', 'whatsapp')
            ->assertJsonPath('data.recipient_number', '+24177123456')
            ->assertJsonPath('data.template.name', 'order_confirmation');

        $message = Message::where('message_id', $response->json('data.message_id'))->firstOrFail();
        $this->assertSame('rentree-2026', $message->campaign_id);

        $whatsapp = WhatsAppMessage::where('message_id', $message->id)->firstOrFail();
        $this->assertSame($template->id, $whatsapp->whatsapp_template_id);
        $this->assertSame('order_confirmation_biz3_0922101530', $whatsapp->provider_template_name);
        $this->assertSame('111222333444555', $whatsapp->sender_phone_number_id);
        $this->assertSame(['1' => 'Awa', '2' => 'CMD-001'], $whatsapp->template_variables);
        $this->assertSame('Bonjour Awa, votre commande CMD-001 est confirmée.', $whatsapp->content);

        Queue::assertPushedOn('whatsapp', SendMessagesJob::class);
        $this->assertSame(1, $template->fresh()->usage_count);
    }

    public function test_the_template_can_be_named_and_variables_given_as_a_list(): void
    {
        Queue::fake();
        $this->approvedTemplate();

        $this->send([
            'template_name' => 'order_confirmation',
            'recipient_number' => '0024177123456',
            'variables' => ['Awa', 'CMD-001'],
        ])->assertCreated()->assertJsonPath('data.recipient_number', '+24177123456');

        $this->assertSame(['1' => 'Awa', '2' => 'CMD-001'], WhatsAppMessage::first()->template_variables);
    }

    public function test_a_name_used_in_both_languages_needs_the_language(): void
    {
        Queue::fake();
        $this->approvedTemplate();
        $this->approvedTemplate(['language' => 'en', 'provider_template_id' => 56, 'provider_template_name' => 'order_confirmation_biz3_0922101531']);

        $this->send(['template_name' => 'order_confirmation', 'recipient_number' => '+24177123456', 'variables' => ['A', 'B']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('language');

        $this->send(['template_name' => 'order_confirmation', 'language' => 'en', 'recipient_number' => '+24177123456', 'variables' => ['A', 'B']])
            ->assertCreated()
            ->assertJsonPath('data.template.language', 'en');
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: int, 2: string}>
     */
    public static function refusedRequests(): array
    {
        return [
            'draft template' => [['status' => 'draft', 'provider_template_name' => null], 422, 'template'],
            'pending template' => [['status' => 'pending'], 422, 'template'],
            'inactive template' => [['is_active' => false], 422, 'template'],
        ];
    }

    #[DataProvider('refusedRequests')]
    public function test_only_approved_active_templates_can_be_sent(array $attributes, int $status, string $field): void
    {
        Queue::fake();
        $template = $this->approvedTemplate($attributes);

        $this->send(['template_id' => $template->id, 'recipient_number' => '+24177123456', 'variables' => ['A', 'B']])
            ->assertStatus($status)
            ->assertJsonValidationErrors($field);

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_another_applications_template_is_unknown(): void
    {
        Queue::fake();
        $foreign = WhatsappTemplate::factory()->create(['status' => 'approved', 'provider_template_name' => 'x_biz9_1']);

        $this->send(['template_id' => $foreign->id, 'recipient_number' => '+24177123456'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('template');
    }

    public function test_variables_are_checked_like_meta_would(): void
    {
        Queue::fake();
        $template = $this->approvedTemplate();

        $this->send(['template_id' => $template->id, 'recipient_number' => '+24177123456', 'variables' => ['1' => 'Awa']])
            ->assertStatus(422)
            ->assertJsonPath('errors.variables.0', 'Provide a value for {{2}}.');

        $this->send(['template_id' => $template->id, 'recipient_number' => '+24177123456', 'variables' => ['Awa', "CMD\n001"]])
            ->assertStatus(422)
            ->assertJsonPath('errors.variables.0', '{{2}} cannot contain line breaks, tabs or more than 4 consecutive spaces.');

        Queue::assertNothingPushed();
    }

    public function test_a_local_number_is_refused(): void
    {
        Queue::fake();
        $template = $this->approvedTemplate();

        $this->send(['template_id' => $template->id, 'recipient_number' => '07712345', 'variables' => ['A', 'B']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('recipient_number');
    }

    public function test_without_a_working_ayospush_configuration_nothing_is_queued(): void
    {
        Queue::fake();
        $template = $this->approvedTemplate();
        $payload = ['template_id' => $template->id, 'recipient_number' => '+24177123456', 'variables' => ['A', 'B']];

        WhatsappSetting::query()->update(['default_phone_number_id' => null]);
        $this->send($payload)->assertStatus(503)->assertJsonPath('message', 'No WhatsApp sender number is selected for this application.');

        WhatsappSetting::query()->update(['test_status' => 'failed']);
        $this->send($payload)->assertStatus(503)->assertJsonPath('message', 'WhatsApp is not configured for this application.');

        Queue::assertNothingPushed();
    }

    public function test_the_sendable_templates_are_listed_with_their_variables(): void
    {
        $this->approvedTemplate();
        $this->approvedTemplate(['name' => 'pending_one', 'status' => 'pending', 'provider_template_id' => 57, 'provider_template_name' => 'p_biz3_1']);

        $this->asApplication()->getJson('/api/v1/app/templates/whatsapp')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'order_confirmation')
            ->assertJsonPath('data.0.variables', ['1', '2']);
    }

    public function test_the_status_endpoint_shows_the_whatsapp_details(): void
    {
        Queue::fake();
        $template = $this->approvedTemplate();
        $id = $this->send(['template_id' => $template->id, 'recipient_number' => '+24177123456', 'variables' => ['A', 'B']])
            ->json('data.message_id');

        $this->asApplication()->getJson("/api/v1/app/messages/{$id}")
            ->assertOk()
            ->assertJsonPath('data.recipient', '+24177123456')
            ->assertJsonPath('data.whatsapp.template', 'order_confirmation_biz3_0922101530')
            ->assertJsonPath('data.whatsapp.content', 'Bonjour A, votre commande B est confirmée.');
    }
}
