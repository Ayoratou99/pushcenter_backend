<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\WhatsappSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesAyosPush;
use Tests\TestCase;

/**
 * The WhatsApp tab of an application: its AyosPush API key and the
 * connection test.
 */
class WhatsappSettingsTest extends TestCase
{
    use FakesAyosPush;
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->actingAsUser($this->globalManager());
    }

    private function url(string $suffix = '', ?int $businessId = null): string
    {
        return '/api/v1/businesses/' . ($businessId ?? $this->business->id) . '/whatsapp-settings' . $suffix;
    }

    private function configure(array $attributes = []): WhatsappSetting
    {
        return WhatsappSetting::factory()->forBusiness($this->business)->create(array_merge([
            'api_key' => 'ak_live_123',
            'api_secret' => 'as_secret_value_9876',
        ], $attributes));
    }

    public function test_an_unconfigured_application_has_no_settings(): void
    {
        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonMissingPath('data');
    }

    public function test_the_secret_is_stored_encrypted_and_never_returned(): void
    {
        $response = $this->putJson($this->url(), [
            'api_key' => 'ak_live_123',
            'api_secret' => 'as_secret_value_9876',
        ])->assertOk()
            ->assertJsonPath('data.api_key', 'ak_live_123')
            ->assertJsonPath('data.api_secret_hint', '9876')
            ->assertJsonPath('data.has_secret', true)
            ->assertJsonPath('data.test_status', 'not_tested');

        $this->assertStringNotContainsString('as_secret_value_9876', $response->getContent());

        $stored = DB::table('whatsapp_settings')->where('business_id', $this->business->id)->value('api_secret');
        $this->assertNotSame('as_secret_value_9876', $stored);
        $this->assertSame('as_secret_value_9876', WhatsappSetting::first()->api_secret);

        $this->assertStringNotContainsString('as_secret_value_9876', $this->getJson($this->url())->getContent());
    }

    public function test_the_secret_is_required_the_first_time_then_kept_when_left_empty(): void
    {
        $this->putJson($this->url(), ['api_key' => 'ak_live_123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('api_secret');

        $this->configure();

        $this->putJson($this->url(), ['api_key' => 'ak_live_123', 'api_secret' => ''])->assertOk();

        $this->assertSame('as_secret_value_9876', WhatsappSetting::first()->api_secret);
    }

    public function test_changing_the_key_resets_the_test_and_what_the_old_key_could_see(): void
    {
        $this->configure(WhatsappSetting::factory()->connected()->raw([
            'business_id' => $this->business->id,
            'api_key' => 'ak_live_123',
        ]));

        $this->putJson($this->url(), ['api_key' => 'ak_other_456', 'api_secret' => 'as_other_secret'])
            ->assertOk()
            ->assertJsonPath('data.test_status', 'not_tested')
            ->assertJsonPath('data.waba_accounts', [])
            ->assertJsonPath('data.default_waba_account_id', null);
    }

    public function test_the_default_account_must_be_one_the_key_reaches(): void
    {
        $this->configure(WhatsappSetting::factory()->connected()->raw([
            'business_id' => $this->business->id,
            'api_key' => 'ak_live_123',
        ]));

        $this->putJson($this->url(), ['api_key' => 'ak_live_123', 'default_waba_account_id' => 999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('default_waba_account_id');
    }

    public function test_the_connection_test_logs_in_and_lists_accounts_and_numbers(): void
    {
        $this->fakeAyosPush();
        $this->configure();

        $this->postJson($this->url('/test'))
            ->assertOk()
            ->assertJsonPath('message', 'Connected to AyosPush')
            ->assertJsonPath('data.test_status', 'success')
            ->assertJsonPath('data.meta_business_id', '998877665544')
            ->assertJsonPath('data.waba_accounts.0.id', 7)
            ->assertJsonPath('data.phone_numbers.0.phone_number_id', '111222333444555')
            // Only one of each: picked automatically.
            ->assertJsonPath('data.default_waba_account_id', 7)
            ->assertJsonPath('data.default_phone_number_id', '111222333444555')
            ->assertJsonPath('data.scopes', ['*'])
            ->assertJsonPath('data.missing_scopes', []);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://ayospush.test/api/v1/auth/login'
            && $request['api_key'] === 'ak_live_123'
            && $request['api_secret'] === 'as_secret_value_9876');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://ayospush.test/api/v1/facebook-config'
            && $request->hasHeader('Authorization', 'Bearer ' . $this->ayosPushToken));

        // The refresh endpoint revokes the token it is called with.
        $this->assertCount(0, $this->ayosPushRequests('POST /auth/refresh'));
    }

    public function test_missing_scopes_are_reported_after_a_successful_test(): void
    {
        $this->fakeAyosPush([
            'POST /auth/login' => fn () => $this->ayosPushLoginResponse(['config.read', 'templates.read']),
        ]);
        $this->configure();

        $this->postJson($this->url('/test'))
            ->assertOk()
            ->assertJsonPath('data.missing_scopes', ['templates.write'])
            ->assertJsonPath('data.missing_sending_scopes', ['templates.send', 'messages.read'])
            ->assertJsonPath('message', 'Connected to AyosPush, but the API key is missing: templates.write');
    }

    public function test_wrong_credentials_are_reported_without_ending_the_console_session(): void
    {
        $this->fakeAyosPush([
            'POST /auth/login' => fn () => $this->ayosPushError(401, 'Invalid API credentials'),
        ]);
        $this->configure();

        // 422, not 401: the console would take a 401 for its own session.
        $this->postJson($this->url('/test'))
            ->assertStatus(422)
            ->assertJsonPath('message', 'AyosPush rejected the API credentials: Invalid API credentials')
            ->assertJsonPath('errors.provider_status', 401)
            ->assertJsonPath('errors.settings.test_status', 'failed');

        $this->assertSame('failed', WhatsappSetting::first()->test_status);
        $this->assertSame('AyosPush rejected the API credentials: Invalid API credentials', WhatsappSetting::first()->test_error);
    }

    public function test_a_revoked_key_is_reported_although_ayospush_answers_200(): void
    {
        // AyosPush answers "Authentication successful" with no token for a
        // revoked or expired scoped key.
        $this->fakeAyosPush([
            'POST /auth/login' => fn () => Http::response([
                'success' => true,
                'data' => ['error' => 'Cette clé API a été révoquée.'],
                'message' => 'Authentication successful',
            ]),
        ]);
        $this->configure();

        $this->postJson($this->url('/test'))
            ->assertStatus(422)
            ->assertJsonPath('message', 'AyosPush rejected the API credentials: Cette clé API a été révoquée.');

        $this->assertCount(0, $this->ayosPushRequests('GET /facebook-config'));
    }

    public function test_a_key_without_the_config_scope_gets_an_explanation(): void
    {
        $this->fakeAyosPush([
            'POST /auth/login' => fn () => $this->ayosPushLoginResponse(['templates.read']),
            'GET /facebook-config' => fn () => Http::response([
                'success' => false,
                'error' => 'Forbidden',
                'message' => "Cette clé API n'est pas autorisée à effectuer l'action « Lire la configuration Facebook ».",
                'required_scope' => 'config.read',
                'granted_scopes' => ['templates.read'],
            ], 403),
        ]);
        $this->configure();

        $this->postJson($this->url('/test'))
            ->assertStatus(422)
            ->assertJsonPath('errors.required_scope', 'config.read')
            ->assertJsonPath('message', "The AyosPush API key is not allowed to do this: Cette clé API n'est pas autorisée à effectuer l'action « Lire la configuration Facebook ». (missing scope: config.read)");

        $this->assertSame(['templates.read'], WhatsappSetting::first()->scopes);
    }

    public function test_an_ayospush_account_without_facebook_connection_is_explained(): void
    {
        $this->fakeAyosPush([
            'GET /facebook-config' => fn () => $this->ayosPushError(404, 'No Facebook configuration found for this business. Please connect Facebook in the manager dashboard.'),
        ]);
        $this->configure();

        $this->postJson($this->url('/test'))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Not found on AyosPush: No Facebook configuration found for this business. Please connect Facebook in the manager dashboard.');
    }

    public function test_an_empty_balance_is_explained(): void
    {
        $this->fakeAyosPush([
            'GET /facebook-config' => fn () => Http::response([
                'success' => false,
                'error' => 'Payment Required',
                'message' => 'Solde insuffisant pour traiter cette requête API.',
            ], 402),
        ]);
        $this->configure();

        $this->postJson($this->url('/test'))
            ->assertStatus(422)
            ->assertJsonPath('message', 'AyosPush balance or quota exhausted: Solde insuffisant pour traiter cette requête API.');
    }

    public function test_an_unreachable_ayospush_is_a_bad_gateway(): void
    {
        config(['services.ayospush.base_url' => $this->ayosPushUrl]);
        Http::fake(['*' => Http::failedConnection('cURL error 7: Failed to connect')]);
        $this->configure();

        $this->postJson($this->url('/test'))
            ->assertStatus(502)
            ->assertJsonPath('errors.provider_status', 0);

        $this->assertStringStartsWith('AyosPush could not be reached', WhatsappSetting::first()->test_error);
    }

    public function test_removing_the_settings(): void
    {
        $this->configure();

        $this->deleteJson($this->url())->assertOk();

        $this->assertDatabaseCount('whatsapp_settings', 0);
        $this->deleteJson($this->url())->assertNotFound();
    }

    public function test_a_restricted_manager_only_reaches_its_own_applications(): void
    {
        $other = Business::factory()->create();
        WhatsappSetting::factory()->forBusiness($other)->create();
        $this->actingAsUser($this->restrictedManager([$this->business->id]));

        $this->getJson($this->url('', $other->id))->assertForbidden();
        $this->putJson($this->url('', $other->id), ['api_key' => 'x', 'api_secret' => 'y'])->assertForbidden();
        $this->postJson($this->url('/test', $other->id))->assertForbidden();
        $this->deleteJson($this->url('', $other->id))->assertForbidden();

        $this->getJson($this->url())->assertOk();
    }
}
