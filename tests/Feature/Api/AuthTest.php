<?php

namespace Tests\Feature\Api;

use App\Models\RefreshToken;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function validCodeFor(User $user): string
    {
        return app(Google2FA::class)->getCurrentOtp($user->two_factor_secret);
    }

    public function test_login_rejects_unknown_credentials(): void
    {
        User::factory()->create(['email' => 'someone@example.com']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'someone@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(401)->assertJson(['success' => false]);
    }

    public function test_login_rejects_a_disabled_account(): void
    {
        User::factory()->inactive()->twoFactorEnabled()->create([
            'email' => 'disabled@example.com',
            'password' => 'password123',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'disabled@example.com',
            'password' => 'password123',
        ])->assertStatus(403);
    }

    public function test_login_asks_for_the_two_factor_setup_when_it_was_never_configured(): void
    {
        User::factory()->withoutTwoFactor()->create([
            'email' => 'fresh@example.com',
            'password' => 'password123',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'fresh@example.com',
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('data.two_factor_setup_required', true)
            ->assertJsonStructure(['data' => ['setup_token', 'user']]);
    }

    public function test_login_returns_a_challenge_when_two_factor_is_enabled(): void
    {
        User::factory()->twoFactorEnabled()->create([
            'email' => 'secured@example.com',
            'password' => 'password123',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'secured@example.com',
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('data.two_factor_required', true)
            ->assertJsonStructure(['data' => ['challenge_token']]);
    }

    public function test_the_challenge_can_be_completed_with_a_valid_totp_code(): void
    {
        $user = User::factory()->twoFactorEnabled()->create([
            'email' => 'secured@example.com',
            'password' => 'password123',
        ]);

        $challenge = $this->postJson('/api/v1/auth/login', [
            'email' => 'secured@example.com',
            'password' => 'password123',
        ])->json('data.challenge_token');

        $this->postJson('/api/v1/auth/login/two-factor', [
            'challenge_token' => $challenge,
            'code' => $this->validCodeFor($user),
        ])
            ->assertOk()
            ->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'expires_in', 'user']]);

        $this->assertDatabaseCount('refresh_tokens', 1);
    }

    public function test_the_challenge_rejects_a_wrong_code(): void
    {
        User::factory()->twoFactorEnabled()->create([
            'email' => 'secured@example.com',
            'password' => 'password123',
        ]);

        $challenge = $this->postJson('/api/v1/auth/login', [
            'email' => 'secured@example.com',
            'password' => 'password123',
        ])->json('data.challenge_token');

        $this->postJson('/api/v1/auth/login/two-factor', [
            'challenge_token' => $challenge,
            'code' => '000000',
        ])->assertStatus(401);
    }

    public function test_a_recovery_code_works_once(): void
    {
        $user = User::factory()->twoFactorEnabled()->create([
            'email' => 'secured@example.com',
            'password' => 'password123',
        ]);

        $recoveryCode = $user->two_factor_recovery_codes[0];

        $this->postJson('/api/v1/auth/login', [
            'email' => 'secured@example.com',
            'password' => 'password123',
            'code' => $recoveryCode,
        ])->assertOk()->assertJsonStructure(['data' => ['access_token']]);

        // Consumed: the same code must not work a second time.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'secured@example.com',
            'password' => 'password123',
            'code' => $recoveryCode,
        ])->assertStatus(401);
    }

    public function test_login_in_a_single_call_when_the_code_is_provided(): void
    {
        $user = User::factory()->twoFactorEnabled()->create([
            'email' => 'secured@example.com',
            'password' => 'password123',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'secured@example.com',
            'password' => 'password123',
            'code' => $this->validCodeFor($user),
        ])->assertOk()->assertJsonStructure(['data' => ['access_token', 'refresh_token']]);

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_the_two_factor_enrolment_flow(): void
    {
        User::factory()->withoutTwoFactor()->create([
            'email' => 'fresh@example.com',
            'password' => 'password123',
        ]);

        $setupToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'fresh@example.com',
            'password' => 'password123',
        ])->json('data.setup_token');

        $setup = $this->postJson('/api/v1/auth/two-factor/setup', ['setup_token' => $setupToken])
            ->assertOk()
            ->assertJsonStructure(['data' => ['secret', 'otpauth_url', 'qr_code', 'manual_entry_key', 'setup_token', 'instructions']])
            ->json('data');

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $setup['qr_code']);
        $this->assertStringContainsString('otpauth://totp/', $setup['otpauth_url']);

        $code = app(Google2FA::class)->getCurrentOtp($setup['secret']);

        $confirmed = $this->postJson('/api/v1/auth/two-factor/confirm', [
            'setup_token' => $setup['setup_token'],
            'code' => $code,
        ])
            ->assertOk()
            ->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'recovery_codes', 'user']])
            ->json('data');

        $this->assertCount(8, $confirmed['recovery_codes']);
        $this->assertTrue(User::where('email', 'fresh@example.com')->first()->hasTwoFactorEnabled());
    }

    public function test_the_setup_token_survives_a_reload_and_a_wrong_code(): void
    {
        User::factory()->withoutTwoFactor()->create([
            'email' => 'fresh@example.com',
            'password' => 'password123',
        ]);

        $setupToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'fresh@example.com',
            'password' => 'password123',
        ])->json('data.setup_token');

        // Calling setup twice (a page reload, or React's double mount) must work,
        // and must hand back the same token.
        $first = $this->postJson('/api/v1/auth/two-factor/setup', ['setup_token' => $setupToken])
            ->assertOk()->json('data');

        $second = $this->postJson('/api/v1/auth/two-factor/setup', ['setup_token' => $setupToken])
            ->assertOk()->json('data');

        $this->assertSame($setupToken, $first['setup_token']);
        $this->assertSame($setupToken, $second['setup_token']);

        // A wrong code keeps the enrolment open...
        $this->postJson('/api/v1/auth/two-factor/confirm', [
            'setup_token' => $setupToken,
            'code' => '000000',
        ])->assertStatus(422)->assertJsonPath('errors.setup_token', $setupToken);

        // ...and the right one still gets through.
        $this->postJson('/api/v1/auth/two-factor/confirm', [
            'setup_token' => $setupToken,
            'code' => app(Google2FA::class)->getCurrentOtp($second['secret']),
        ])->assertOk();

        // Once confirmed, the token is spent.
        $this->postJson('/api/v1/auth/two-factor/setup', ['setup_token' => $setupToken])
            ->assertStatus(401);
    }

    public function test_the_enrolment_rejects_a_wrong_code(): void
    {
        User::factory()->withoutTwoFactor()->create([
            'email' => 'fresh@example.com',
            'password' => 'password123',
        ]);

        $setupToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'fresh@example.com',
            'password' => 'password123',
        ])->json('data.setup_token');

        $setup = $this->postJson('/api/v1/auth/two-factor/setup', ['setup_token' => $setupToken])->json('data');

        $this->postJson('/api/v1/auth/two-factor/confirm', [
            'setup_token' => $setup['setup_token'],
            'code' => '000000',
        ])->assertStatus(422);

        $this->assertFalse(User::where('email', 'fresh@example.com')->first()->hasTwoFactorEnabled());
    }

    public function test_protected_routes_require_a_token(): void
    {
        $this->getJson('/api/v1/businesses')->assertStatus(401);
    }

    public function test_protected_routes_reject_a_forged_token(): void
    {
        $this->withHeader('Authorization', 'Bearer not-a-real-token')
            ->getJson('/api/v1/businesses')
            ->assertStatus(401);
    }

    public function test_a_user_without_two_factor_cannot_reach_the_application(): void
    {
        $user = User::factory()->withoutTwoFactor()->create();

        $this->actingAsUser($user)
            ->getJson('/api/v1/businesses')
            ->assertStatus(403)
            ->assertJsonPath('code', 'two_factor_setup_required');
    }

    public function test_a_valid_token_reaches_the_application(): void
    {
        $this->actingAsUser($this->globalManager())
            ->getJson('/api/v1/businesses')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_current_user_endpoint(): void
    {
        $user = $this->adminUser(['name' => 'Jane Admin']);

        $this->actingAsUser($user)
            ->getJson('/api/v1/auth/user')
            ->assertOk()
            ->assertJsonPath('data.name', 'Jane Admin')
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.two_factor_enabled', true);
    }

    public function test_refresh_rotates_the_refresh_token(): void
    {
        $user = User::factory()->twoFactorEnabled()->create([
            'email' => 'secured@example.com',
            'password' => 'password123',
        ]);

        $tokens = $this->postJson('/api/v1/auth/login', [
            'email' => 'secured@example.com',
            'password' => 'password123',
            'code' => $this->validCodeFor($user),
        ])->json('data');

        $refreshed = $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $tokens['refresh_token'],
        ])->assertOk()->json('data');

        $this->assertNotSame($tokens['refresh_token'], $refreshed['refresh_token']);

        // The old one is revoked and cannot be replayed.
        $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $tokens['refresh_token'],
        ])->assertStatus(401);
    }

    public function test_refresh_rejects_an_unknown_token(): void
    {
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => 'nope'])->assertStatus(401);
    }

    public function test_logout_revokes_the_refresh_token(): void
    {
        $user = User::factory()->twoFactorEnabled()->create([
            'email' => 'secured@example.com',
            'password' => 'password123',
        ]);

        $tokens = $this->postJson('/api/v1/auth/login', [
            'email' => 'secured@example.com',
            'password' => 'password123',
            'code' => $this->validCodeFor($user),
        ])->json('data');

        $this->actingAsUser($user)
            ->postJson('/api/v1/auth/logout', ['refresh_token' => $tokens['refresh_token']])
            ->assertOk();

        $this->assertNotNull(RefreshToken::first()->revoked_at);
    }

    public function test_password_can_be_changed(): void
    {
        $user = $this->adminUser(['password' => 'password123']);

        $this->actingAsUser($user)
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'password123',
                'password' => 'newPassword456',
                'password_confirmation' => 'newPassword456',
            ])
            ->assertOk()
            ->assertJsonStructure(['data' => ['access_token', 'refresh_token']]);

        $this->assertTrue(Hash::check('newPassword456', $user->fresh()->password));
    }

    public function test_password_change_requires_the_current_password(): void
    {
        $user = $this->adminUser(['password' => 'password123']);

        $this->actingAsUser($user)
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'not-the-one',
                'password' => 'newPassword456',
                'password_confirmation' => 'newPassword456',
            ])
            ->assertStatus(422);

        $this->assertTrue(Hash::check('password123', $user->fresh()->password));
    }

    public function test_password_change_refuses_a_weak_password(): void
    {
        $user = $this->adminUser(['password' => 'password123']);

        $this->actingAsUser($user)
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'password123',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertStatus(422);
    }

    public function test_profile_can_be_updated(): void
    {
        $user = $this->adminUser();

        $this->actingAsUser($user)
            ->putJson('/api/v1/auth/profile', ['name' => 'Updated Name', 'phone' => '+237690000000'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Name');
    }

    public function test_two_factor_can_be_reset_by_its_owner(): void
    {
        $user = $this->adminUser(['password' => 'password123']);

        $this->actingAsUser($user)
            ->postJson('/api/v1/auth/two-factor/disable', ['password' => 'password123'])
            ->assertOk();

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_two_factor_reset_requires_the_password(): void
    {
        $user = $this->adminUser(['password' => 'password123']);

        $this->actingAsUser($user)
            ->postJson('/api/v1/auth/two-factor/disable', ['password' => 'wrong'])
            ->assertStatus(422);

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_the_totp_service_rejects_malformed_codes(): void
    {
        $service = app(TwoFactorService::class);
        $secret = $service->generateSecret();

        $this->assertFalse($service->verify($secret, 'abcdef'));
        $this->assertFalse($service->verify($secret, '12345'));
        $this->assertTrue($service->verify($secret, app(Google2FA::class)->getCurrentOtp($secret)));
    }
}
