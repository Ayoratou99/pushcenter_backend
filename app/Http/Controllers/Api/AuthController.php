<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Services\JwtService;
use App\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Internal authentication: email + password, a mandatory Google Authenticator
 * second factor, and JWT access tokens with rotating refresh tokens.
 *
 * @OA\Tag(name="Authentication", description="Internal JWT authentication")
 */
class AuthController extends BaseController
{
    /**
     * A partially authenticated session (password OK, 2FA pending) lives this long.
     */
    private const CHALLENGE_TTL_MINUTES = 10;

    /**
     * Wrong second-factor codes tolerated before the account is locked out,
     * and how long that lockout lasts.
     */
    private const SECOND_FACTOR_MAX_ATTEMPTS = 5;

    private const SECOND_FACTOR_LOCK_SECONDS = 300;

    public function __construct(
        private JwtService $jwt,
        private TwoFactorService $twoFactor,
    ) {
    }

    /**
     * @OA\Post(
     *     path="/api/v1/auth/login",
     *     tags={"Authentication"},
     *     summary="Login with email and password",
     *     description="Step 1 of the login. Returns tokens directly when the account still has to configure Google Authenticator, otherwise returns a challenge token to be completed with /auth/login/two-factor.",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email", "password"},
     *             @OA\Property(property="email", type="string", format="email", example="admin@example.com"),
     *             @OA\Property(property="password", type="string", format="password", example="secret123"),
     *             @OA\Property(property="code", type="string", description="Optional 6 digit Google Authenticator code, lets you log in in a single call", example="123456")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Authenticated, or two-factor challenge required"),
     *     @OA\Response(response=401, description="Invalid credentials"),
     *     @OA\Response(response=403, description="Account disabled"),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=429, description="Too many attempts")
     * )
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
            'code' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $throttleKey = 'login:' . Str::lower($request->input('email')) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return $this->errorResponse(
                'Too many login attempts. Please try again in ' . RateLimiter::availableIn($throttleKey) . ' seconds.',
                null,
                429
            );
        }

        $user = User::where('email', $request->input('email'))->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            RateLimiter::hit($throttleKey, 300);

            return $this->errorResponse('Invalid email or password', null, 401);
        }

        if (! $user->is_active) {
            return $this->errorResponse('This account has been disabled. Contact an administrator.', null, 403);
        }

        RateLimiter::clear($throttleKey);

        // Google Authenticator not configured yet: hand over a short lived token
        // so the client can walk the user through the setup wizard.
        if (! $user->hasTwoFactorEnabled()) {
            return $this->successResponse([
                'two_factor_setup_required' => true,
                'setup_token' => $this->issueChallenge($user, 'setup'),
                'user' => $this->userPayload($user),
            ], 'Two-factor authentication must be configured before you can continue');
        }

        // Single call login when the client already sent the code.
        if ($request->filled('code')) {
            if (! $this->checkSecondFactor($user, $request->input('code'))) {
                RateLimiter::hit($throttleKey, 300);

                return $this->errorResponse('Invalid authentication code', null, 401);
            }

            return $this->grantTokens($user, $request);
        }

        return $this->successResponse([
            'two_factor_required' => true,
            'challenge_token' => $this->issueChallenge($user, 'login'),
        ], 'Enter the code from your Google Authenticator app');
    }

    /**
     * @OA\Post(
     *     path="/api/v1/auth/login/two-factor",
     *     tags={"Authentication"},
     *     summary="Complete the login with a Google Authenticator code",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"challenge_token", "code"},
     *             @OA\Property(property="challenge_token", type="string"),
     *             @OA\Property(property="code", type="string", example="123456", description="6 digit TOTP code or a recovery code")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Authenticated"),
     *     @OA\Response(response=401, description="Invalid or expired challenge")
     * )
     */
    public function loginTwoFactor(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'challenge_token' => 'required|string',
            'code' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $user = $this->consumeChallenge($request->input('challenge_token'), 'login');

        if (! $user) {
            return $this->errorResponse('Invalid or expired challenge. Please sign in again.', null, 401);
        }

        // A failed code hands back a fresh challenge, so without this the six
        // digits could be brute forced one request at a time.
        if ($locked = $this->throttleSecondFactor($user, 'login')) {
            return $locked;
        }

        if (! $this->checkSecondFactor($user, $request->input('code'))) {
            RateLimiter::hit($this->secondFactorKey($user, 'login'), self::SECOND_FACTOR_LOCK_SECONDS);

            // Keep the challenge alive so the user can retype the code.
            return $this->errorResponse('Invalid authentication code', [
                'challenge_token' => $this->issueChallenge($user, 'login'),
            ], 401);
        }

        RateLimiter::clear($this->secondFactorKey($user, 'login'));

        return $this->grantTokens($user, $request);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/auth/two-factor/setup",
     *     tags={"Authentication"},
     *     summary="Start the Google Authenticator enrolment",
     *     description="Works either with a setup_token returned by /auth/login, or with a valid access token for a user who wants to re-enrol.",
     *     @OA\RequestBody(@OA\JsonContent(@OA\Property(property="setup_token", type="string"))),
     *     @OA\Response(response=200, description="Secret, otpauth URL and QR code")
     * )
     */
    public function setupTwoFactor(Request $request): JsonResponse
    {
        $user = $this->resolveSetupUser($request);

        if (! $user) {
            return $this->errorResponse('Invalid or expired setup session. Please sign in again.', null, 401);
        }

        $secret = $this->twoFactor->generateSecret();

        // Stored but not confirmed: the account stays without 2FA until a valid
        // code proves the app was configured correctly.
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
        ])->save();

        return $this->successResponse(array_merge(
            $this->twoFactor->enrolmentPayload($user, $secret),
            [
                'setup_token' => $request->input('setup_token') ?: $this->issueChallenge($user, 'setup'),
                'instructions' => $this->setupInstructions(),
            ]
        ), 'Scan the QR code with Google Authenticator, then confirm with a code');
    }

    /**
     * @OA\Post(
     *     path="/api/v1/auth/two-factor/confirm",
     *     tags={"Authentication"},
     *     summary="Confirm the Google Authenticator enrolment and finish logging in",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"code"},
     *             @OA\Property(property="setup_token", type="string"),
     *             @OA\Property(property="code", type="string", example="123456")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Enabled, returns tokens and recovery codes")
     * )
     */
    public function confirmTwoFactor(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'setup_token' => 'nullable|string',
            'code' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $user = $this->resolveSetupUser($request);

        if (! $user) {
            return $this->errorResponse('Invalid or expired setup session. Please sign in again.', null, 401);
        }

        if (! $user->two_factor_secret) {
            return $this->errorResponse('Start the enrolment first.', null, 422);
        }

        // The setup token stays valid for its whole TTL so a reload does not
        // break the enrolment; that makes throttling the codes necessary.
        if ($locked = $this->throttleSecondFactor($user, 'setup')) {
            return $locked;
        }

        if (! $this->twoFactor->verify($user->two_factor_secret, (string) $request->input('code'))) {
            RateLimiter::hit($this->secondFactorKey($user, 'setup'), self::SECOND_FACTOR_LOCK_SECONDS);

            return $this->errorResponse('Invalid authentication code. Check that your phone clock is on automatic time.', [
                'setup_token' => $request->input('setup_token') ?: $this->issueChallenge($user, 'setup'),
            ], 422);
        }

        RateLimiter::clear($this->secondFactorKey($user, 'setup'));

        $recoveryCodes = User::generateRecoveryCodes();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $recoveryCodes,
        ])->save();

        // The enrolment is done; the setup token must not open it again.
        $this->forgetChallenge($request->input('setup_token'), 'setup');

        $tokens = $this->grantTokensPayload($user, $request);

        return $this->successResponse(array_merge($tokens, [
            'recovery_codes' => $recoveryCodes,
            'user' => $this->userPayload($user->fresh()),
        ]), 'Two-factor authentication enabled. Store your recovery codes somewhere safe.');
    }

    /**
     * @OA\Post(
     *     path="/api/v1/auth/two-factor/disable",
     *     tags={"Authentication"},
     *     summary="Reset the Google Authenticator enrolment of the current user",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Reset; a new enrolment is required on next login")
     * )
     */
    public function disableTwoFactor(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $user = $request->user();

        if (! Hash::check($request->input('password'), $user->password)) {
            return $this->errorResponse('The password is incorrect', null, 422);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        // Every session must re-enrol.
        $this->jwt->revokeAllRefreshTokens($user);

        return $this->successResponse(null, 'Two-factor authentication reset. You will be asked to configure it again on your next login.');
    }

    /**
     * @OA\Post(
     *     path="/api/v1/auth/two-factor/recovery-codes",
     *     tags={"Authentication"},
     *     summary="Regenerate the recovery codes",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="New recovery codes")
     * )
     */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            return $this->errorResponse('Two-factor authentication is not enabled.', null, 422);
        }

        $codes = User::generateRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();

        return $this->successResponse(['recovery_codes' => $codes], 'Recovery codes regenerated');
    }

    /**
     * @OA\Post(
     *     path="/api/v1/auth/refresh",
     *     tags={"Authentication"},
     *     summary="Exchange a refresh token for a new access token",
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"refresh_token"},
     *         @OA\Property(property="refresh_token", type="string")
     *     )),
     *     @OA\Response(response=200, description="New token pair"),
     *     @OA\Response(response=401, description="Invalid refresh token")
     * )
     */
    public function refresh(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'refresh_token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $token = $this->jwt->findValidRefreshToken($request->input('refresh_token'));

        if (! $token) {
            return $this->errorResponse('Invalid or expired refresh token', null, 401);
        }

        $user = $token->user;

        if (! $user || ! $user->is_active) {
            $token->revoke();

            return $this->errorResponse('This account has been disabled.', null, 403);
        }

        return $this->successResponse(
            $this->jwt->rotate($token, $user, $request),
            'Token refreshed successfully'
        );
    }

    /**
     * @OA\Post(
     *     path="/api/v1/auth/logout",
     *     tags={"Authentication"},
     *     summary="Revoke the refresh token",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(@OA\JsonContent(
     *         @OA\Property(property="refresh_token", type="string"),
     *         @OA\Property(property="all_devices", type="boolean", example=false)
     *     )),
     *     @OA\Response(response=200, description="Logged out")
     * )
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($request->boolean('all_devices')) {
            $this->jwt->revokeAllRefreshTokens($user);

            return $this->successResponse(null, 'Logged out from every device');
        }

        if ($request->filled('refresh_token')) {
            $this->jwt->findValidRefreshToken($request->input('refresh_token'))?->revoke();
        }

        return $this->successResponse(null, 'Logout successful');
    }

    /**
     * @OA\Get(
     *     path="/api/v1/auth/user",
     *     tags={"Authentication"},
     *     summary="Current authenticated user",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="User profile"),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function user(Request $request): JsonResponse
    {
        return $this->successResponse($this->userPayload($request->user()));
    }

    /**
     * @OA\Put(
     *     path="/api/v1/auth/profile",
     *     tags={"Authentication"},
     *     summary="Update the current user's own profile",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Profile updated")
     * )
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|max:255|unique:users,email,' . $user->getKey(),
            'phone' => 'sometimes|nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $user->fill($request->only(['name', 'email', 'phone']))->save();

        return $this->updatedResponse($this->userPayload($user->fresh()), 'Profile updated successfully');
    }

    /**
     * @OA\Put(
     *     path="/api/v1/auth/password",
     *     tags={"Authentication"},
     *     summary="Change the current user's password",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"current_password", "password", "password_confirmation"},
     *         @OA\Property(property="current_password", type="string"),
     *         @OA\Property(property="password", type="string"),
     *         @OA\Property(property="password_confirmation", type="string")
     *     )),
     *     @OA\Response(response=200, description="Password changed, new tokens issued")
     * )
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::min(8)->letters()->numbers()],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        if (! Hash::check($request->input('current_password'), $user->password)) {
            return $this->errorResponse('The current password is incorrect', [
                'current_password' => ['The current password is incorrect.'],
            ], 422);
        }

        if (Hash::check($request->input('password'), $user->password)) {
            return $this->errorResponse('The new password must be different from the current one', [
                'password' => ['The new password must be different from the current one.'],
            ], 422);
        }

        $user->forceFill([
            'password' => $request->input('password'),
            'must_change_password' => false,
        ])->save();

        // Invalidate other sessions, then hand this client a fresh pair.
        $this->jwt->revokeAllRefreshTokens($user);

        return $this->successResponse(
            $this->jwt->tokenPayload($user, null, $request),
            'Password updated successfully'
        );
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function grantTokens(User $user, Request $request): JsonResponse
    {
        return $this->successResponse(
            array_merge($this->grantTokensPayload($user, $request), ['user' => $this->userPayload($user)]),
            'Login successful'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function grantTokensPayload(User $user, Request $request): array
    {
        $user->forceFill(['last_login_at' => now()])->save();

        return $this->jwt->tokenPayload($user, null, $request);
    }

    /**
     * Short lived, single use token proving the password step already passed.
     */
    private function issueChallenge(User $user, string $purpose): string
    {
        $token = Str::random(64);

        Cache::put(
            $this->challengeKey($token, $purpose),
            $user->getKey(),
            now()->addMinutes(self::CHALLENGE_TTL_MINUTES)
        );

        return $token;
    }

    /**
     * Resolve a challenge token.
     *
     * Login challenges are single use: a code either works or a new challenge is
     * issued. Setup challenges stay valid for their whole TTL, because the
     * enrolment takes several calls (start, then confirm, possibly retried) and
     * the page may be reloaded in between.
     */
    private function consumeChallenge(?string $token, string $purpose, bool $consume = true): ?User
    {
        if (! $token) {
            return null;
        }

        $key = $this->challengeKey($token, $purpose);
        $userId = $consume ? Cache::pull($key) : Cache::get($key);

        return $userId ? User::find($userId) : null;
    }

    private function forgetChallenge(?string $token, string $purpose): void
    {
        if ($token) {
            Cache::forget($this->challengeKey($token, $purpose));
        }
    }

    private function challengeKey(string $token, string $purpose): string
    {
        return "auth:2fa:{$purpose}:" . hash('sha256', $token);
    }

    /**
     * The setup endpoints accept either a setup_token (first login) or a normal
     * access token (a signed-in user re-enrolling).
     */
    private function resolveSetupUser(Request $request): ?User
    {
        if ($request->filled('setup_token')) {
            return $this->consumeChallenge($request->input('setup_token'), 'setup', consume: false);
        }

        return $request->user();
    }

    /**
     * Throttle key for the second factor, scoped to the user rather than the IP:
     * the challenge already identifies them, so rotating IPs must not help.
     */
    private function secondFactorKey(User $user, string $purpose): string
    {
        return "2fa:{$purpose}:" . $user->getKey();
    }

    /**
     * Returns a 429 response once too many codes have been tried, or null.
     */
    private function throttleSecondFactor(User $user, string $purpose): ?JsonResponse
    {
        $key = $this->secondFactorKey($user, $purpose);

        if (! RateLimiter::tooManyAttempts($key, self::SECOND_FACTOR_MAX_ATTEMPTS)) {
            return null;
        }

        return $this->errorResponse(
            'Too many authentication codes tried. Please wait ' . RateLimiter::availableIn($key) . ' seconds.',
            null,
            429
        );
    }

    private function checkSecondFactor(User $user, string $code): bool
    {
        if ($this->twoFactor->verify((string) $user->two_factor_secret, $code)) {
            return true;
        }

        return $this->twoFactor->consumeRecoveryCode($user, $code);
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        $user->loadMissing('businesses:id,name');

        return array_merge($user->only([
            'id', 'name', 'email', 'phone', 'role', 'scope', 'is_active',
            'must_change_password', 'last_login_at', 'created_at',
        ]), [
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
            'global_scope' => $user->hasUnrestrictedAccess(),
            'businesses' => $user->businesses->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->values(),
        ]);
    }

    /**
     * Step by step guide displayed by the frontend setup wizard.
     *
     * @return array<int, array{title: string, description: string}>
     */
    private function setupInstructions(): array
    {
        return [
            [
                'title' => 'Install Google Authenticator',
                'description' => 'Download Google Authenticator (or any TOTP app such as Authy or 1Password) from the App Store or Google Play.',
            ],
            [
                'title' => 'Add the account',
                'description' => 'Open the app, tap the + button, then choose "Scan a QR code" and point your camera at the code displayed here. If you cannot scan it, choose "Enter a setup key" and type the key shown below the QR code.',
            ],
            [
                'title' => 'Confirm the code',
                'description' => 'The app now shows a 6 digit code that changes every 30 seconds. Type the current code below to finish the setup.',
            ],
            [
                'title' => 'Save your recovery codes',
                'description' => 'Once confirmed you receive single use recovery codes. Keep them somewhere safe: they are the only way back in if you lose your phone.',
            ],
        ];
    }
}
