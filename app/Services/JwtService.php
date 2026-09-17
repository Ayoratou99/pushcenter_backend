<?php

namespace App\Services;

use App\Models\RefreshToken;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Issues and validates the internal JWT access tokens and their companion
 * opaque refresh tokens.
 */
class JwtService
{
    /**
     * Build a signed access token for the given user.
     */
    public function issueAccessToken(User $user): string
    {
        $now = time();

        $payload = [
            'iss' => config('jwt.issuer'),
            'aud' => config('jwt.audience'),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + (config('jwt.access_ttl') * 60),
            'jti' => (string) Str::uuid(),
            'sub' => (string) $user->getKey(),
            'email' => $user->email,
            'name' => $user->name,
            'role' => $user->role,
            'scope' => $user->scope,
        ];

        return JWT::encode($payload, $this->secret(), config('jwt.algo'));
    }

    /**
     * Decode a token and return its claims, or null when invalid/expired.
     *
     * @return array<string, mixed>|null
     */
    public function decode(string $token): ?array
    {
        try {
            JWT::$leeway = (int) config('jwt.leeway');

            $decoded = JWT::decode($token, new Key($this->secret(), config('jwt.algo')));

            $claims = json_decode(json_encode($decoded), true);

            if (($claims['aud'] ?? null) !== config('jwt.audience')) {
                return null;
            }

            return $claims;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Create a refresh token row and return the plain token to hand to the client.
     */
    public function issueRefreshToken(User $user, ?Request $request = null): string
    {
        $plain = Str::random(80);

        RefreshToken::create([
            'user_id' => $user->getKey(),
            'token_hash' => RefreshToken::hash($plain),
            'ip_address' => $request?->ip(),
            'user_agent' => Str::limit((string) $request?->userAgent(), 255, ''),
            'expires_at' => now()->addMinutes((int) config('jwt.refresh_ttl')),
        ]);

        return $plain;
    }

    /**
     * Find a usable refresh token row from the plain value.
     */
    public function findValidRefreshToken(string $plain): ?RefreshToken
    {
        $token = RefreshToken::where('token_hash', RefreshToken::hash($plain))->first();

        return $token && $token->isValid() ? $token : null;
    }

    /**
     * Rotate a refresh token: revoke the old one and issue a new pair.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, refresh_expires_in: int, token_type: string}
     */
    public function rotate(RefreshToken $token, User $user, ?Request $request = null): array
    {
        $token->revoke();

        return $this->tokenPayload($user, $this->issueRefreshToken($user, $request));
    }

    /**
     * Full token payload returned to the client on login/refresh.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, refresh_expires_in: int, token_type: string}
     */
    public function tokenPayload(User $user, ?string $refreshToken = null, ?Request $request = null): array
    {
        $refreshToken ??= $this->issueRefreshToken($user, $request);

        return [
            'access_token' => $this->issueAccessToken($user),
            'refresh_token' => $refreshToken,
            'expires_in' => (int) config('jwt.access_ttl') * 60,
            'refresh_expires_in' => (int) config('jwt.refresh_ttl') * 60,
            'token_type' => 'Bearer',
        ];
    }

    /**
     * Revoke every refresh token still alive for the user.
     */
    public function revokeAllRefreshTokens(User $user): void
    {
        $user->refreshTokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    /**
     * Delete expired / revoked rows. Called by the scheduler.
     */
    public function pruneExpiredRefreshTokens(): int
    {
        return RefreshToken::where('expires_at', '<', now())
            ->orWhere(fn ($q) => $q->whereNotNull('revoked_at')->where('revoked_at', '<', now()->subDays(7)))
            ->delete();
    }

    /**
     * Signing key for HS256.
     *
     * The configured secret is run through HKDF so any value produces a 32 byte
     * key: RFC 7518 (and php-jwt) require the key to be at least as long as the
     * hash output, and a short JWT_SECRET would otherwise blow up at signing time.
     */
    private function secret(): string
    {
        $secret = (string) config('jwt.secret');

        // APP_KEY is stored base64 encoded; decode it so the raw bytes are used.
        if (Str::startsWith($secret, 'base64:')) {
            $secret = base64_decode(Str::after($secret, 'base64:'));
        }

        if ($secret === '') {
            throw new \RuntimeException('JWT secret is not configured. Set JWT_SECRET or APP_KEY.');
        }

        return hash_hkdf('sha256', $secret, 32, 'aninfpush-jwt');
    }
}
