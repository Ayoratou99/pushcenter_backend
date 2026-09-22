<?php

namespace Tests\Feature;

use App\Http\Middleware\TrustProxies;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies as FrameworkTrustProxies;
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

        // The default of config/trustedproxy.php, whatever TRUSTED_PROXIES the
        // machine running the tests has.
        config(['trustedproxy.proxies' => '*']);

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

    /**
     * Built on APP_URL, not on X-Forwarded-Host / -Proto.
     */
    private function ignoresForwardedHost(string $url): bool
    {
        return ! str_contains($url, 'push.aninf.test') && str_starts_with($url, 'http://');
    }

    public function test_the_application_middleware_replaces_laravels_own(): void
    {
        $global = $this->app->make(Kernel::class)->getGlobalMiddleware();

        $this->assertContains(TrustProxies::class, $global);
        $this->assertNotContains(FrameworkTrustProxies::class, $global);
    }

    public function test_without_trusted_proxies_the_direct_peer_is_trusted(): void
    {
        $saved = [getenv('TRUSTED_PROXIES'), $_ENV['TRUSTED_PROXIES'] ?? null, $_SERVER['TRUSTED_PROXIES'] ?? null];
        putenv('TRUSTED_PROXIES');
        unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);

        try {
            $this->assertSame('*', (require config_path('trustedproxy.php'))['proxies']);
        } finally {
            if ($saved[0] !== false) {
                putenv('TRUSTED_PROXIES=' . $saved[0]);
            }
            if ($saved[1] !== null) {
                $_ENV['TRUSTED_PROXIES'] = $saved[1];
            }
            if ($saved[2] !== null) {
                $_SERVER['TRUSTED_PROXIES'] = $saved[2];
            }
        }
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

    public function test_cidr_ranges_are_accepted(): void
    {
        config(['trustedproxy.proxies' => '172.16.0.0/12, 10.0.0.0/8']);

        $this->withServerVariables(['REMOTE_ADDR' => self::PROXY])
            ->withHeaders($this->forwardedByTraefik())
            ->getJson('/_proxy-probe')
            ->assertJson(['ip' => self::VISITOR, 'secure' => true]);
    }

    // A test of its own: the test client builds the URL of a request from the
    // previous one, which would make this one https from the start.
    public function test_an_address_outside_the_ranges_is_not_trusted(): void
    {
        config(['trustedproxy.proxies' => '172.16.0.0/12, 10.0.0.0/8']);

        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.5'])
            ->withHeaders($this->forwardedByTraefik())
            ->getJson('/_proxy-probe')
            ->assertJson(['ip' => '192.168.1.5', 'secure' => false])
            ->assertJsonPath('url', fn (string $url) => $this->ignoresForwardedHost($url));
    }

    public function test_an_empty_list_trusts_nobody(): void
    {
        config(['trustedproxy.proxies' => '']);

        $this->withServerVariables(['REMOTE_ADDR' => self::PROXY])
            ->withHeaders($this->forwardedByTraefik())
            ->getJson('/_proxy-probe')
            ->assertJson(['ip' => self::PROXY, 'secure' => false])
            ->assertJsonPath('url', fn (string $url) => $this->ignoresForwardedHost($url));
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
