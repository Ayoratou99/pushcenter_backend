<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * TLS ends at Traefik, which forwards plain HTTP with X-Forwarded-* headers.
 */
class TrustedProxiesTest extends TestCase
{
    use RefreshDatabase;

    private const PROXY = '10.0.1.2';

    private const VISITOR = '203.0.113.7';

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_proxy-probe', fn (Request $request) => [
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
            'url' => url('/api/documentation'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function forwardedByTraefik(): array
    {
        return [
            'X-Forwarded-For' => self::VISITOR,
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'push.aninf.test',
            'X-Forwarded-Port' => '443',
        ];
    }

    public function test_the_visitor_ip_and_https_scheme_come_from_the_proxy_headers(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => self::PROXY])
            ->withHeaders($this->forwardedByTraefik())
            ->getJson('/_proxy-probe')
            ->assertOk()
            ->assertJson([
                'ip' => self::VISITOR,
                'secure' => true,
                'url' => 'https://push.aninf.test/api/documentation',
            ]);
    }

    public function test_the_swagger_page_links_its_assets_over_https(): void
    {
        $page = $this->withServerVariables(['REMOTE_ADDR' => self::PROXY])
            ->withHeaders($this->forwardedByTraefik())
            ->get('/api/documentation')
            ->assertOk()
            ->getContent();

        // Mixed content (http:// assets on an https:// page) is blocked by the
        // browser, which left the page blank.
        $this->assertStringContainsString('https://push.aninf.test/', $page);
        $this->assertStringNotContainsString('http://push.aninf.test/', $page);
    }

    public function test_a_restricted_list_ignores_headers_from_other_peers(): void
    {
        config(['trustedproxy.proxies' => self::PROXY]);

        // Not the proxy: its X-Forwarded-For must not be believed.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->withHeaders($this->forwardedByTraefik())
            ->getJson('/_proxy-probe')
            ->assertJson(['ip' => '198.51.100.20', 'secure' => false]);

        $this->withServerVariables(['REMOTE_ADDR' => self::PROXY])
            ->withHeaders($this->forwardedByTraefik())
            ->getJson('/_proxy-probe')
            ->assertJson(['ip' => self::VISITOR, 'secure' => true]);
    }

    public function test_the_login_throttle_counts_per_visitor_not_per_proxy(): void
    {
        $attempt = fn (string $visitor) => $this->withServerVariables(['REMOTE_ADDR' => self::PROXY])
            ->withHeaders(['X-Forwarded-For' => $visitor, 'X-Forwarded-Proto' => 'https'])
            ->postJson('/api/v1/auth/login', ['email' => 'someone@aninf.test', 'password' => 'wrong-password']);

        for ($i = 0; $i < 5; $i++) {
            $attempt(self::VISITOR)->assertStatus(401);
        }
        $attempt(self::VISITOR)->assertStatus(429);

        // Another visitor behind the same proxy is not locked out.
        $attempt('198.51.100.99')->assertStatus(401);
    }
}
