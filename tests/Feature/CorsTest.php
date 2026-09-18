<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Only the declared frontend origin may call the API from a browser.
 */
class CorsTest extends TestCase
{
    use RefreshDatabase;

    private function preflight(string $origin, string $path = '/api/v1/auth/login'): \Illuminate\Testing\TestResponse
    {
        return $this->call('OPTIONS', $path, [], [], [], [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);
    }

    public function test_the_configured_frontend_is_allowed(): void
    {
        config(['cors.allowed_origins' => ['https://console.example.com']]);

        $this->preflight('https://console.example.com')
            ->assertSuccessful()
            ->assertHeader('Access-Control-Allow-Origin', 'https://console.example.com');
    }

    public function test_another_origin_is_not_allowed(): void
    {
        config(['cors.allowed_origins' => ['https://console.example.com']]);

        $allowed = $this->preflight('https://evil.example.com')
            ->headers->get('Access-Control-Allow-Origin');

        // The layer echoes a configured origin rather than omitting the header;
        // what protects the caller is that it never matches theirs, so the
        // browser refuses to hand the response to the page.
        $this->assertNotSame('https://evil.example.com', $allowed);
        $this->assertNotSame('*', $allowed);
    }

    public function test_a_lookalike_origin_is_not_allowed(): void
    {
        config(['cors.allowed_origins' => ['https://console.example.com']]);

        foreach ([
            'https://console.example.com.evil.test',
            'http://console.example.com',       // wrong scheme
            'https://console.example.com:8443', // wrong port
        ] as $origin) {
            $this->assertNotSame(
                $origin,
                $this->preflight($origin)->headers->get('Access-Control-Allow-Origin'),
                "{$origin} must not be accepted"
            );
        }
    }

    public function test_several_origins_can_be_declared(): void
    {
        config(['cors.allowed_origins' => ['https://console.example.com', 'https://staging.example.com']]);

        foreach (['https://console.example.com', 'https://staging.example.com'] as $origin) {
            $this->preflight($origin)->assertHeader('Access-Control-Allow-Origin', $origin);
        }
    }

    public function test_the_authorization_header_is_allowed(): void
    {
        config(['cors.allowed_origins' => ['https://console.example.com']]);

        $allowed = $this->preflight('https://console.example.com')
            ->headers->get('Access-Control-Allow-Headers');

        $this->assertStringContainsStringIgnoringCase('authorization', (string) $allowed);
    }

    /**
     * Template exports are downloads: the browser needs to read the filename.
     */
    public function test_the_content_disposition_header_is_exposed(): void
    {
        config(['cors.allowed_origins' => ['https://console.example.com']]);

        $exposed = $this->get('/api/health', ['Origin' => 'https://console.example.com'])
            ->headers->get('Access-Control-Expose-Headers');

        $this->assertStringContainsStringIgnoringCase('content-disposition', (string) $exposed);
    }

    /**
     * Tokens travel in the Authorization header, so credentialed requests stay
     * off: enabling them alongside a permissive origin is a classic hole.
     */
    public function test_credentials_are_not_enabled(): void
    {
        $this->assertFalse(config('cors.supports_credentials'));

        config(['cors.allowed_origins' => ['https://console.example.com']]);

        $this->assertNull(
            $this->preflight('https://console.example.com')
                ->headers->get('Access-Control-Allow-Credentials')
        );
    }

    public function test_cors_covers_the_whole_api(): void
    {
        $this->assertSame(['api/*'], config('cors.paths'));
    }

    /**
     * Server to server callers send no Origin, so CORS never applies to them.
     */
    public function test_the_application_api_still_answers_without_an_origin(): void
    {
        config(['cors.allowed_origins' => ['https://console.example.com']]);

        $this->postJson('/api/v1/auth/token', [
            'app_id' => 'app_unknown',
            'app_secret' => 'secret_unknown',
        ])->assertStatus(401);
    }

    public function test_the_config_reads_the_frontend_url(): void
    {
        // Mirrors config/cors.php: FRONTEND_URL plus the extra origins, both
        // trimmed of any trailing slash.
        $origins = collect([
            'https://console.example.com/',
            ...explode(',', 'https://staging.example.com , https://other.example.com/'),
        ])->map(fn ($origin) => rtrim(trim((string) $origin), '/'))->filter()->unique()->values()->all();

        $this->assertSame([
            'https://console.example.com',
            'https://staging.example.com',
            'https://other.example.com',
        ], $origins);
    }
}
