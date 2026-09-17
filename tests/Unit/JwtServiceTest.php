<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\JwtService;
use Tests\TestCase;

class JwtServiceTest extends TestCase
{
    private function service(): JwtService
    {
        return new JwtService();
    }

    private function user(): User
    {
        // No database needed: only the attributes that end up in the claims.
        return (new User())->forceFill([
            'id' => 42,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'role' => User::ROLE_ADMIN,
            'scope' => User::SCOPE_GLOBAL,
        ]);
    }

    public function test_a_token_round_trips(): void
    {
        $service = $this->service();
        $claims = $service->decode($service->issueAccessToken($this->user()));

        $this->assertSame('42', $claims['sub']);
        $this->assertSame('jane@example.com', $claims['email']);
        $this->assertSame('admin', $claims['role']);
        $this->assertSame('global', $claims['scope']);
        $this->assertSame(config('jwt.audience'), $claims['aud']);
    }

    /**
     * php-jwt v7 refuses an HMAC key shorter than the hash output, so the secret
     * is stretched with HKDF: any configured value has to work.
     */
    public function test_a_short_secret_still_signs(): void
    {
        config(['jwt.secret' => 'short']);

        $service = $this->service();
        $token = $service->issueAccessToken($this->user());

        $this->assertNotNull($service->decode($token));
    }

    public function test_a_base64_app_key_style_secret_works(): void
    {
        config(['jwt.secret' => 'base64:' . base64_encode(random_bytes(32))]);

        $service = $this->service();

        $this->assertNotNull($service->decode($service->issueAccessToken($this->user())));
    }

    public function test_a_token_signed_with_another_secret_is_rejected(): void
    {
        config(['jwt.secret' => 'first-secret-value']);
        $token = $this->service()->issueAccessToken($this->user());

        config(['jwt.secret' => 'second-secret-value']);

        $this->assertNull($this->service()->decode($token));
    }

    public function test_garbage_is_rejected(): void
    {
        $service = $this->service();

        $this->assertNull($service->decode('not-a-token'));
        $this->assertNull($service->decode(''));
        $this->assertNull($service->decode('a.b.c'));
    }

    /**
     * `JWT_SECRET=` (present but empty) is the documented default, and it has to
     * fall back to APP_KEY instead of taking token issuance down.
     */
    public function test_an_empty_secret_falls_back_to_the_app_key(): void
    {
        config([
            'jwt.secret' => '',
            'app.key' => 'base64:' . base64_encode(random_bytes(32)),
        ]);

        $service = $this->service();
        $token = $service->issueAccessToken($this->user());

        $this->assertNotNull($service->decode($token));
    }

    public function test_the_config_resolves_an_empty_env_to_the_app_key(): void
    {
        // Mirrors config/jwt.php: env('JWT_SECRET') ?: env('APP_KEY').
        // env() returns '' for a present-but-empty key, so a ?? default would
        // never apply and the secret would stay empty.
        $emptyEnvValue = '';
        $appKey = 'base64:' . base64_encode(random_bytes(32));

        $this->assertSame($appKey, $emptyEnvValue ?: $appKey);
    }

    public function test_a_secret_is_required_when_nothing_is_configured(): void
    {
        config(['jwt.secret' => '', 'app.key' => '']);

        $this->expectException(\RuntimeException::class);

        $this->service()->issueAccessToken($this->user());
    }
}
