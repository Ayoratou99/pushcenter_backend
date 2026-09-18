<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessCredentialsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser($this->globalManager());
    }

    public function test_creating_an_application_generates_its_credentials(): void
    {
        $response = $this->postJson('/api/v1/businesses', [
            'name' => 'Brand new app',
            'email' => 'contact@brandnew.test',
        ])->assertStatus(201);

        $appId = $response->json('data.credentials.app_id');
        $appSecret = $response->json('data.credentials.app_secret');

        $this->assertStringStartsWith('app_', $appId);
        $this->assertStringStartsWith('secret_', $appSecret);
        // 'app_' + 32 hex chars, 'secret_' + 64 hex chars
        $this->assertSame(36, strlen($appId));
        $this->assertSame(71, strlen($appSecret));

        // Only the hash is stored; the clear secret never touches the database.
        $business = Business::where('email', 'contact@brandnew.test')->first();

        $this->assertNotNull($business->app_secret_hash);
        $this->assertNotSame($appSecret, $business->app_secret_hash);
        $this->assertTrue($business->checkAppSecret($appSecret));
        $this->assertSame(substr($appSecret, -4), $business->app_secret_hint);
    }

    public function test_the_model_generates_credentials_even_outside_the_api(): void
    {
        $business = Business::factory()->create();

        $this->assertNotNull($business->app_id);
        $this->assertNotNull($business->app_secret_hash);
        // Readable once, on the instance that generated it.
        $this->assertNotNull($business->getPlainAppSecret());
        $this->assertNull(Business::find($business->id)->getPlainAppSecret());
    }

    public function test_credentials_are_unique_per_application(): void
    {
        $first = Business::factory()->create();
        $second = Business::factory()->create();

        $this->assertNotSame($first->app_id, $second->app_id);
        $this->assertNotSame($first->getPlainAppSecret(), $second->getPlainAppSecret());
        // A secret must not validate against the other application.
        $this->assertFalse($second->checkAppSecret($first->getPlainAppSecret()));
    }

    public function test_an_explicit_app_id_is_not_overwritten(): void
    {
        $business = Business::factory()->create(['app_id' => 'app_custom']);

        $this->assertSame('app_custom', $business->app_id);
        // A secret is still issued, since only its hash can be stored.
        $this->assertNotNull($business->app_secret_hash);
    }

    public function test_credentials_can_be_regenerated(): void
    {
        $business = Business::factory()->create();
        $originalId = $business->app_id;
        $originalSecret = $business->getPlainAppSecret();

        $response = $this->postJson("/api/v1/businesses/{$business->id}/regenerate-credentials")
            ->assertOk()
            ->assertJsonStructure(['data' => ['app_id', 'app_secret']]);

        $this->assertNotSame($originalId, $response->json('data.app_id'));
        $this->assertNotSame($originalSecret, $response->json('data.app_secret'));

        $this->assertSame($response->json('data.app_id'), $business->fresh()->app_id);
        // The rotated secret works and the previous one no longer does.
        $this->assertTrue($business->fresh()->checkAppSecret($response->json('data.app_secret')));
        $this->assertFalse($business->fresh()->checkAppSecret($originalSecret));
    }

    public function test_regenerating_credentials_of_a_missing_application_returns_404(): void
    {
        $this->postJson('/api/v1/businesses/999999/regenerate-credentials')->assertStatus(404);
    }

    public function test_creating_an_application_validates_the_payload(): void
    {
        $this->postJson('/api/v1/businesses', ['name' => '', 'email' => 'nope'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['name', 'email']]);
    }

    public function test_the_email_must_be_unique(): void
    {
        Business::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/v1/businesses', [
            'name' => 'Duplicate',
            'email' => 'taken@example.com',
        ])->assertStatus(422);
    }
}
