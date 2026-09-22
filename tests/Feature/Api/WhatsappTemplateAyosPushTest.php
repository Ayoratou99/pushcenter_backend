<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\WhatsappSetting;
use App\Models\WhatsappTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesAyosPush;
use Tests\TestCase;

/**
 * WhatsApp templates go to Meta through AyosPush: creation as draft,
 * submission (POST /v1/templates), status synchronisation and import.
 */
class WhatsappTemplateAyosPushTest extends TestCase
{
    use FakesAyosPush;
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create(['name' => 'Guichet ANINF']);
        $this->actingAsUser($this->globalManager());
    }

    private function connect(array $attributes = []): WhatsappSetting
    {
        return WhatsappSetting::factory()->connected()->forBusiness($this->business)->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function draft(array $attributes = []): WhatsappTemplate
    {
        return WhatsappTemplate::factory()->forBusiness($this->business)->create(array_merge([
            'name' => 'order_confirmation',
            'display_name' => 'Order confirmation',
            'language' => 'fr',
            'category' => 'UTILITY',
            'header' => ['format' => 'TEXT', 'text' => 'Commande confirmée'],
            'body' => 'Bonjour {{1}}, votre commande {{2}} est confirmée.',
            'footer' => ['text' => 'Merci de votre confiance'],
            'buttons' => [
                ['type' => 'URL', 'text' => 'Suivre', 'url' => 'https://example.test/track'],
                ['type' => 'PHONE_NUMBER', 'text' => 'Appeler', 'phone_number' => '+241 77 00 00 00'],
                ['type' => 'QUICK_REPLY', 'text' => 'Merci'],
            ],
            'sample_data' => ['1' => 'Awa', '2' => 'CMD-001'],
            'status' => 'draft',
        ], $attributes));
    }

    private function fakeSubmissionAccepted(): void
    {
        $this->fakeAyosPush([
            'POST /templates' => fn () => Http::response([
                'success' => true,
                'data' => [
                    'template_id' => 55,
                    'name' => 'order_confirmation_biz3_0922101530',
                    'display_name' => 'Order confirmation',
                    'facebook_status' => 'pending',
                    'facebook_template_id' => '1234567890',
                ],
                'message' => 'Template created and submitted for Facebook approval',
            ]),
        ]);
    }

    /* ------------------------------ Drafts ------------------------------ */

    public function test_a_template_is_always_created_as_a_draft(): void
    {
        $payload = [
            'business_id' => $this->business->id,
            'name' => 'welcome',
            'display_name' => 'Welcome',
            'language' => 'fr',
            'category' => 'MARKETING',
            'body' => 'Bienvenue {{1}} !',
        ];

        $this->postJson('/api/v1/whatsapp-templates', $payload + ['status' => 'approved'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->postJson('/api/v1/whatsapp-templates', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');
    }

    public function test_content_is_normalised_when_saved(): void
    {
        $id = $this->postJson('/api/v1/whatsapp-templates', [
            'business_id' => $this->business->id,
            'name' => 'reminder',
            'display_name' => 'Reminder',
            'language' => 'fr',
            'category' => 'utility',
            'header' => ['type' => 'text', 'text' => 'Rappel'],
            'body' => 'Bonjour {{1}}, rendez-vous le {{2}}.',
            'footer' => 'ANINF',
            'buttons' => [['type' => 'phone_number', 'text' => 'Appeler', 'phone' => '+24177000000']],
            'sample_data' => ['1' => 'Awa', '2' => '12 octobre'],
        ])->assertCreated()->json('data.id');

        $template = WhatsappTemplate::find($id);
        $this->assertSame('UTILITY', $template->category);
        $this->assertSame(['format' => 'TEXT', 'text' => 'Rappel'], $template->header);
        $this->assertSame(['text' => 'ANINF'], $template->footer);
        $this->assertSame([['type' => 'PHONE_NUMBER', 'text' => 'Appeler', 'phone_number' => '+24177000000']], $template->buttons);
        $this->assertSame(['1', '2'], $template->variables);
    }

    public function test_only_the_languages_ayospush_accepts_can_be_used(): void
    {
        $this->postJson('/api/v1/whatsapp-templates', [
            'business_id' => $this->business->id,
            'name' => 'hola',
            'display_name' => 'Hola',
            'language' => 'es',
            'category' => 'MARKETING',
            'body' => 'Hola',
        ])->assertStatus(422)->assertJsonValidationErrors('language');
    }

    public function test_a_duplicate_name_is_a_validation_error_not_a_crash(): void
    {
        $this->draft();

        $this->postJson('/api/v1/whatsapp-templates', [
            'business_id' => $this->business->id,
            'name' => 'order_confirmation',
            'display_name' => 'Again',
            'language' => 'fr',
            'category' => 'UTILITY',
            'body' => 'Bonjour',
        ])->assertStatus(422)->assertJsonValidationErrors('name');

        // Same name in the other language is fine.
        $this->postJson('/api/v1/whatsapp-templates', [
            'business_id' => $this->business->id,
            'name' => 'order_confirmation',
            'display_name' => 'Again',
            'language' => 'en',
            'category' => 'UTILITY',
            'body' => 'Hello',
        ])->assertCreated();
    }

    public function test_status_cannot_be_forced_through_an_update(): void
    {
        $template = $this->draft();

        $this->putJson("/api/v1/whatsapp-templates/{$template->id}", ['status' => 'approved'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame('draft', $template->fresh()->status);
    }

    /* ---------------------------- Submission ---------------------------- */

    public function test_submission_sends_what_ayospush_expects(): void
    {
        $this->fakeSubmissionAccepted();
        $this->connect();
        $template = $this->draft();

        $this->postJson("/api/v1/whatsapp-templates/{$template->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.provider', 'ayospush')
            ->assertJsonPath('data.provider_template_id', 55)
            ->assertJsonPath('data.provider_template_name', 'order_confirmation_biz3_0922101530')
            ->assertJsonPath('data.facebook_template_id', '1234567890')
            ->assertJsonPath('data.provider_error', null);

        $sent = $this->ayosPushRequests('POST /templates')->first();
        $this->assertNotNull($sent);
        $this->assertTrue($sent->hasHeader('Authorization', 'Bearer ' . $this->ayosPushToken));
        $this->assertSame('order_confirmation', $sent['name']);
        $this->assertSame('Order confirmation', $sent['display_name']);
        $this->assertSame('fr', $sent['language']);
        $this->assertSame('UTILITY', $sent['category']);
        $this->assertSame(7, $sent['waba_account_id']);
        $this->assertTrue($sent['submit_for_approval']);
        $this->assertSame(['1' => 'Awa', '2' => 'CMD-001'], $sent['variable_examples']);

        // AyosPush validates `components` with the `json` rule: a string.
        $this->assertIsString($sent['components']);
        $this->assertSame([
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Commande confirmée'],
            ['type' => 'BODY', 'text' => 'Bonjour {{1}}, votre commande {{2}} est confirmée.'],
            ['type' => 'FOOTER', 'text' => 'Merci de votre confiance'],
            ['type' => 'BUTTONS', 'buttons' => [
                ['type' => 'URL', 'text' => 'Suivre', 'url' => 'https://example.test/track'],
                ['type' => 'PHONE_NUMBER', 'text' => 'Appeler', 'phone_number' => '+24177000000'],
                ['type' => 'QUICK_REPLY', 'text' => 'Merci'],
            ]],
        ], json_decode($sent['components'], true));

        $template->refresh();
        $this->assertNotNull($template->submitted_at);
        $this->assertTrue($template->isContentLocked());
    }

    public function test_a_media_header_forwards_its_url(): void
    {
        $this->fakeSubmissionAccepted();
        $this->connect();
        $template = $this->draft([
            'header' => ['format' => 'IMAGE', 'media_url' => 'https://ayospush.test/storage/template-media/3/logo.png', 'media_file_size' => 2048],
        ]);

        $this->postJson("/api/v1/whatsapp-templates/{$template->id}/submit")->assertOk();

        $components = json_decode($this->ayosPushRequests('POST /templates')->first()['components'], true);
        $this->assertSame([
            'type' => 'HEADER',
            'format' => 'IMAGE',
            'media_url' => 'https://ayospush.test/storage/template-media/3/logo.png',
            'media_file_size' => 2048,
        ], $components[0]);
    }

    public function test_an_authentication_template_sends_its_expiration_and_no_components(): void
    {
        $this->fakeSubmissionAccepted();
        $this->connect();
        $template = $this->draft([
            'category' => 'AUTHENTICATION',
            'header' => null,
            'footer' => null,
            'buttons' => null,
            'body' => '{{1}} est votre code de vérification.',
            'sample_data' => null,
            'metadata' => ['code_expiration_minutes' => 10, 'add_security_recommendation' => true],
        ]);

        $this->postJson("/api/v1/whatsapp-templates/{$template->id}/submit")->assertOk();

        $sent = $this->ayosPushRequests('POST /templates')->first();
        $this->assertSame('AUTHENTICATION', $sent['category']);
        $this->assertSame(10, $sent['code_expiration_minutes']);
        $this->assertTrue($sent['add_security_recommendation']);
        $this->assertArrayNotHasKey('components', $sent->data());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function unacceptableTemplates(): array
    {
        return [
            'named variable' => [['body' => 'Bonjour {{name}}', 'sample_data' => null], 'body'],
            'gap in the numbering' => [['body' => 'Bonjour {{1}} et {{3}}', 'sample_data' => ['1' => 'a', '3' => 'b']], 'body'],
            'missing example' => [['sample_data' => ['1' => 'Awa']], 'sample_data'],
            'variable in the header' => [['header' => ['format' => 'TEXT', 'text' => 'Commande {{1}}']], 'header'],
            'media header without upload' => [['header' => ['format' => 'VIDEO']], 'header'],
            'variable in the footer' => [['footer' => ['text' => 'Code {{1}}']], 'footer'],
            'dynamic URL button' => [['buttons' => [['type' => 'URL', 'text' => 'Voir', 'url' => 'https://example.test/{{1}}']]], 'buttons'],
            'phone without country code' => [['buttons' => [['type' => 'PHONE_NUMBER', 'text' => 'Appeler', 'phone_number' => '077000000']]], 'buttons'],
            'catalog button' => [['buttons' => [['type' => 'CATALOG', 'text' => 'Catalogue']]], 'buttons'],
            'three URL buttons' => [['buttons' => array_fill(0, 3, ['type' => 'URL', 'text' => 'Voir', 'url' => 'https://example.test'])], 'buttons'],
            'language AyosPush refuses' => [['language' => 'es'], 'language'],
            'authentication without expiration' => [['category' => 'AUTHENTICATION', 'metadata' => null], 'code_expiration_minutes'],
        ];
    }

    #[DataProvider('unacceptableTemplates')]
    public function test_what_ayospush_or_meta_would_refuse_is_caught_before_calling_them(array $attributes, string $field): void
    {
        $this->fakeAyosPush();
        $this->connect();
        $template = $this->draft($attributes);

        $this->postJson("/api/v1/whatsapp-templates/{$template->id}/submit")
            ->assertStatus(422)
            ->assertJsonPath('message', 'The template cannot be submitted to AyosPush yet.')
            ->assertJsonStructure(['errors' => [$field]]);

        Http::assertNothingSent();
        $this->assertSame('draft', $template->fresh()->status);
    }

    public function test_submission_needs_the_application_to_be_configured(): void
    {
        $this->fakeAyosPush();
        $template = $this->draft();

        $this->postJson("/api/v1/whatsapp-templates/{$template->id}/submit")
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['whatsapp_settings']]);
    }

    public function test_submission_needs_a_chosen_whatsapp_business_account(): void
    {
        $this->fakeAyosPush();
        $this->connect([
            'default_waba_account_id' => null,
            'waba_accounts' => [['id' => 7, 'waba_id' => '1'], ['id' => 8, 'waba_id' => '2']],
        ]);
        $template = $this->draft();

        $this->postJson("/api/v1/whatsapp-templates/{$template->id}/submit")
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['waba_account_id']]);
    }

    public function test_a_meta_refusal_keeps_the_draft_and_its_reason(): void
    {
        $this->fakeAyosPush([
            'POST /templates' => fn () => $this->ayosPushError(422, 'Facebook error: Invalid parameter', ['errors' => ['code' => 100]]),
        ]);
        $this->connect();
        $template = $this->draft();

        $this->postJson("/api/v1/whatsapp-templates/{$template->id}/submit")
            ->assertStatus(422)
            ->assertJsonPath('message', 'AyosPush refused the request: Facebook error: Invalid parameter')
            ->assertJsonPath('errors.errors.code', 100);

        $template->refresh();
        $this->assertSame('draft', $template->status);
        $this->assertNull($template->provider_template_id);
        $this->assertSame('AyosPush refused the request: Facebook error: Invalid parameter', $template->provider_error);
    }

    public function test_a_submitted_template_cannot_be_submitted_again(): void
    {
        $this->fakeSubmissionAccepted();
        $this->connect();
        $template = $this->draft();

        $this->postJson("/api/v1/whatsapp-templates/{$template->id}/submit")->assertOk();
        $this->postJson("/api/v1/whatsapp-templates/{$template->id}/submit")->assertStatus(409);

        $this->assertCount(1, $this->ayosPushRequests('POST /templates'));
    }

    /* ------------------------- Locked content --------------------------- */

    public function test_submitted_content_is_frozen_but_its_description_is_not(): void
    {
        $template = $this->draft([
            'status' => 'approved',
            'provider' => 'ayospush',
            'provider_template_id' => 55,
            'provider_template_name' => 'order_confirmation_biz3_0922101530',
        ]);

        $this->putJson("/api/v1/whatsapp-templates/{$template->id}", ['body' => 'Autre texte {{1}}'])
            ->assertStatus(422)
            ->assertJsonPath('errors.locked_fields', ['body']);

        // The console re-sends the whole form: unchanged content is no edit.
        $this->putJson("/api/v1/whatsapp-templates/{$template->id}", [
            'display_name' => 'Confirmation de commande',
            'body' => $template->body,
            'header' => ['type' => 'text', 'text' => 'Commande confirmée'],
            'category' => 'utility',
        ])->assertOk()->assertJsonPath('data.display_name', 'Confirmation de commande');
    }

    public function test_a_rejected_template_can_be_fixed_and_submitted_again(): void
    {
        $this->fakeSubmissionAccepted();
        $this->connect();
        $template = $this->draft([
            'status' => 'rejected',
            'provider' => 'ayospush',
            'provider_template_id' => 41,
            'provider_template_name' => 'order_confirmation_biz3_0101000000',
            'rejection_reason' => 'Rejected by Meta.',
        ]);

        $this->putJson("/api/v1/whatsapp-templates/{$template->id}", ['body' => 'Bonjour {{1}}, commande {{2}} validée.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');

        $this->postJson("/api/v1/whatsapp-templates/{$template->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.provider_template_id', 55)
            ->assertJsonPath('data.rejection_reason', null);
    }

    /* -------------------------- Synchronisation ------------------------- */

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function remoteStatuses(): array
    {
        return [
            'approved' => ['APPROVED', 'approved'],
            'rejected' => ['REJECTED', 'rejected'],
            'in appeal' => ['IN_APPEAL', 'pending'],
            'paused by Meta' => ['PAUSED', 'disabled'],
            'disabled by Meta' => ['DISABLED', 'disabled'],
            'unknown status' => ['SOMETHING_NEW', 'pending'],
        ];
    }

    #[DataProvider('remoteStatuses')]
    public function test_the_status_of_a_template_is_pulled_from_ayospush(string $remote, string $expected): void
    {
        $this->fakeAyosPush([
            'GET /templates/55' => fn () => Http::response([
                'success' => true,
                'data' => $this->ayosPushListedTemplate(['status' => $remote]),
                'message' => 'Success',
            ]),
        ]);
        $this->connect();
        $template = $this->draft([
            'status' => 'pending',
            'provider' => 'ayospush',
            'provider_template_id' => 55,
            'provider_template_name' => 'order_confirmation_biz3_0922101530',
        ]);

        $this->postJson("/api/v1/whatsapp-templates/{$template->id}/sync")
            ->assertOk()
            ->assertJsonPath('data.status', $expected)
            ->assertJsonPath('data.facebook_status', $remote);

        $template->refresh();
        $this->assertSame($expected === 'approved', $template->approved_at !== null);
        $this->assertSame($expected === 'rejected', $template->rejection_reason !== null);
    }

    public function test_an_approved_template_can_then_be_activated(): void
    {
        $this->fakeAyosPush([
            'GET /templates/55' => fn () => Http::response(['success' => true, 'data' => $this->ayosPushListedTemplate()]),
        ]);
        $this->connect();
        $template = $this->draft([
            'status' => 'pending',
            'is_active' => false,
            'provider' => 'ayospush',
            'provider_template_id' => 55,
        ]);

        $this->postJson("/api/v1/whatsapp-templates/{$template->id}/activate")->assertStatus(400);
        $this->postJson("/api/v1/whatsapp-templates/{$template->id}/sync")->assertOk();
        $this->postJson("/api/v1/whatsapp-templates/{$template->id}/activate")->assertOk();

        $this->assertTrue($template->fresh()->isReady());
    }

    public function test_a_template_gone_from_ayospush_keeps_its_status_and_says_so(): void
    {
        $this->fakeAyosPush([
            'GET /templates/55' => fn () => $this->ayosPushError(404, 'Template not found'),
        ]);
        $this->connect();
        $template = $this->draft(['status' => 'approved', 'provider' => 'ayospush', 'provider_template_id' => 55]);

        $this->postJson("/api/v1/whatsapp-templates/{$template->id}/sync")->assertOk();

        $template->refresh();
        $this->assertSame('approved', $template->status);
        $this->assertStringContainsString('no longer lists this template', $template->provider_error);
    }

    public function test_a_draft_cannot_be_synchronised(): void
    {
        $this->fakeAyosPush();
        $this->connect();
        $template = $this->draft();

        $this->postJson("/api/v1/whatsapp-templates/{$template->id}/sync")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This template has not been submitted to AyosPush yet.');
    }

    public function test_the_application_sync_updates_imports_and_flags_what_disappeared(): void
    {
        $withoutComponents = $this->ayosPushListedTemplate([
            'id' => 58,
            'name' => 'otp_code',
            'display_name' => 'Code OTP',
            'category' => 'AUTHENTICATION',
            'status' => 'APPROVED',
            'components' => null,
        ]);

        $this->fakeAyosPush([
            'GET /templates/58' => fn () => Http::response(['success' => true, 'data' => array_merge($withoutComponents, [
                'body' => '*{{1}}* est votre code de vérification.',
                'footer' => ['text' => 'Ce code expire dans 10 minutes.'],
                'header' => null,
                'buttons' => [['type' => 'OTP', 'otp_type' => 'COPY_CODE', 'text' => 'Copier le code']],
                'sample_data' => null,
            ])]),
            'GET /templates' => fn (Request $request) => (int) ($this->ayosPushQuery($request)['page'] ?? 1) === 1
                ? $this->ayosPushTemplatesPage([
                    $this->ayosPushListedTemplate(['id' => 55, 'status' => 'APPROVED']),
                    $this->ayosPushListedTemplate(['id' => 56, 'name' => 'promo_biz3_0922101600', 'display_name' => 'Promo', 'category' => 'MARKETING', 'status' => 'PENDING']),
                ], 1, 2)
                : $this->ayosPushTemplatesPage([
                    $withoutComponents,
                    $this->ayosPushListedTemplate(['id' => 60, 'name' => 'deleted_here', 'status' => 'APPROVED']),
                ], 2, 2),
        ]);
        $this->connect();

        $pending = $this->draft(['status' => 'pending', 'provider' => 'ayospush', 'provider_template_id' => 55]);
        $vanished = $this->draft(['name' => 'old', 'status' => 'approved', 'provider' => 'ayospush', 'provider_template_id' => 40]);
        $deleted = $this->draft(['name' => 'deleted_here', 'status' => 'approved', 'provider' => 'ayospush', 'provider_template_id' => 60]);
        $deleted->delete();

        $this->postJson("/api/v1/businesses/{$this->business->id}/whatsapp-templates/sync")
            ->assertOk()
            ->assertJsonPath('data', ['updated' => 1, 'unchanged' => 0, 'imported' => 2, 'missing' => 1, 'ignored' => 1])
            ->assertJsonPath('message', '1 updated, 2 imported, 0 unchanged, 1 no longer on AyosPush');

        $this->assertSame('approved', $pending->fresh()->status);
        $this->assertStringContainsString('no longer lists', $vanished->fresh()->provider_error);
        $this->assertSame(1, WhatsappTemplate::onlyTrashed()->count(), 'A template deleted here is not imported back.');

        $promo = WhatsappTemplate::where('provider_template_id', 56)->firstOrFail();
        $this->assertSame('promo_biz3_0922101600', $promo->name);
        $this->assertSame('promo_biz3_0922101600', $promo->provider_template_name);
        $this->assertSame('pending', $promo->status);
        $this->assertSame('MARKETING', $promo->category);
        $this->assertSame('Bonjour {{1}}, votre commande {{2}} est confirmée.', $promo->body);
        $this->assertSame(['format' => 'TEXT', 'text' => 'Commande confirmée'], $promo->header);
        $this->assertSame(['text' => 'Merci de votre confiance'], $promo->footer);
        $this->assertSame([['type' => 'URL', 'text' => 'Suivre', 'url' => 'https://example.test/track']], $promo->buttons);
        $this->assertSame(['1' => 'Awa', '2' => 'CMD-001'], $promo->sample_data);
        $this->assertTrue($promo->isContentLocked());

        // Not in the listing's components: read from the detail endpoint.
        $otp = WhatsappTemplate::where('provider_template_id', 58)->firstOrFail();
        $this->assertSame('*{{1}}* est votre code de vérification.', $otp->body);
        $this->assertSame('approved', $otp->status);
        $this->assertNotNull($otp->approved_at);

        $this->assertNotNull(WhatsappSetting::first()->templates_synced_at);
        $this->assertCount(2, $this->ayosPushRequests('GET /templates'));
    }

    public function test_an_imported_template_never_clashes_with_a_local_name(): void
    {
        $this->fakeAyosPush([
            'GET /templates' => fn () => $this->ayosPushTemplatesPage([
                $this->ayosPushListedTemplate(['id' => 70, 'name' => 'order_confirmation']),
            ]),
        ]);
        $this->connect();
        $this->draft(['name' => 'order_confirmation']);

        $this->postJson("/api/v1/businesses/{$this->business->id}/whatsapp-templates/sync")->assertOk();

        $imported = WhatsappTemplate::where('provider_template_id', 70)->firstOrFail();
        $this->assertSame('order_confirmation_2', $imported->name);
        $this->assertSame('order_confirmation', $imported->provider_template_name);
    }

    public function test_the_application_sync_can_skip_the_import(): void
    {
        $this->fakeAyosPush([
            'GET /templates' => fn () => $this->ayosPushTemplatesPage([$this->ayosPushListedTemplate(['id' => 71])]),
        ]);
        $this->connect();

        $this->postJson("/api/v1/businesses/{$this->business->id}/whatsapp-templates/sync", ['import' => false])
            ->assertOk()
            ->assertJsonPath('data.imported', 0)
            ->assertJsonPath('data.ignored', 1);

        $this->assertDatabaseCount('whatsapp_templates', 0);
    }

    /* ---------------------------- AyosPush token ------------------------ */

    public function test_the_token_is_reused_then_renewed_when_refused(): void
    {
        $calls = 0;
        $this->fakeAyosPush([
            'GET /templates' => function () use (&$calls) {
                $calls++;

                // Third listing: AyosPush no longer knows the token.
                return $calls === 3
                    ? Http::response(['success' => false, 'error' => 'Unauthorized', 'message' => 'Invalid or expired token'], 401)
                    : $this->ayosPushTemplatesPage([]);
            },
        ]);
        $this->connect();
        $url = "/api/v1/businesses/{$this->business->id}/whatsapp-templates/sync";

        $this->postJson($url)->assertOk();
        $this->postJson($url)->assertOk();
        $this->assertCount(1, $this->ayosPushRequests('POST /auth/login'), 'The cached token is reused.');

        $this->postJson($url)->assertOk();
        $this->assertCount(2, $this->ayosPushRequests('POST /auth/login'), 'A refused token triggers one new login.');
        $this->assertSame(4, $calls, 'The refused call is retried once.');
        $this->assertCount(0, $this->ayosPushRequests('POST /auth/refresh'));
    }

    public function test_new_credentials_never_reuse_the_old_token(): void
    {
        $this->fakeAyosPush(['GET /templates' => fn () => $this->ayosPushTemplatesPage([])]);
        $settings = $this->connect();
        $url = "/api/v1/businesses/{$this->business->id}/whatsapp-templates/sync";

        $this->postJson($url)->assertOk();
        $settings->update(['api_secret' => 'as_rotated_secret']);
        $this->postJson($url)->assertOk();

        $this->assertCount(2, $this->ayosPushRequests('POST /auth/login'));
    }

    public function test_an_ayospush_login_failure_is_not_answered_as_401(): void
    {
        $this->fakeAyosPush(['POST /auth/login' => fn () => $this->ayosPushError(401, 'Invalid API credentials')]);
        $this->connect();

        $this->postJson("/api/v1/businesses/{$this->business->id}/whatsapp-templates/sync")
            ->assertStatus(422)
            ->assertJsonPath('message', 'AyosPush rejected the API credentials: Invalid API credentials');
    }

    public function test_throttling_is_passed_on_with_its_delay(): void
    {
        $this->fakeAyosPush([
            'GET /templates' => fn () => Http::response(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '30']),
        ]);
        $this->connect();

        $this->postJson("/api/v1/businesses/{$this->business->id}/whatsapp-templates/sync")
            ->assertStatus(429)
            ->assertJsonPath('errors.retry_after', 30)
            ->assertJsonPath('message', 'AyosPush rate limit reached: Too Many Attempts. Retry in 30 s.');
    }

    /* ------------------------------ Media ------------------------------- */

    public function test_header_media_is_uploaded_to_ayospush(): void
    {
        $this->fakeAyosPush([
            'POST /templates/upload-media' => fn () => Http::response([
                'success' => true,
                'data' => [
                    'media_url' => 'https://ayospush.test/storage/template-media/3/2026/09/logo.png',
                    'file_name' => 'logo.png',
                    'file_size' => 1024,
                    'mime_type' => 'image/png',
                    'type' => 'image',
                ],
                'message' => 'Media uploaded successfully.',
            ]),
        ]);
        $this->connect();

        $this->post('/api/v1/whatsapp-templates/media', [
            'business_id' => $this->business->id,
            'type' => 'image',
            'file' => UploadedFile::fake()->image('logo.png', 200, 200),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.media_url', 'https://ayospush.test/storage/template-media/3/2026/09/logo.png');

        $upload = $this->ayosPushRequests('POST /templates/upload-media')->first();
        $this->assertTrue($upload->isMultipart());
        $this->assertTrue($upload->hasHeader('Authorization', 'Bearer ' . $this->ayosPushToken));
        $parts = collect($upload->data())->keyBy('name');
        $this->assertSame('image', $parts['type']['contents']);
        $this->assertSame('logo.png', $parts['file']['filename']);
    }

    public function test_a_file_of_the_wrong_kind_is_refused_before_uploading(): void
    {
        $this->fakeAyosPush();
        $this->connect();

        $this->post('/api/v1/whatsapp-templates/media', [
            'business_id' => $this->business->id,
            'type' => 'image',
            'file' => UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        Http::assertNothingSent();
    }

    /* ------------------------------ Scope ------------------------------- */

    public function test_a_restricted_manager_cannot_act_on_another_application(): void
    {
        $this->fakeAyosPush();
        $other = Business::factory()->create();
        $foreign = WhatsappTemplate::factory()->forBusiness($other)->create(['status' => 'draft']);
        $this->actingAsUser($this->restrictedManager([$this->business->id]));

        $this->postJson("/api/v1/whatsapp-templates/{$foreign->id}/submit")->assertForbidden();
        $this->postJson("/api/v1/whatsapp-templates/{$foreign->id}/sync")->assertForbidden();
        $this->postJson("/api/v1/businesses/{$other->id}/whatsapp-templates/sync")->assertForbidden();
        $this->post('/api/v1/whatsapp-templates/media', [
            'business_id' => $other->id,
            'type' => 'image',
            'file' => UploadedFile::fake()->image('logo.png'),
        ], ['Accept' => 'application/json'])->assertForbidden();

        Http::assertNothingSent();
    }
}
