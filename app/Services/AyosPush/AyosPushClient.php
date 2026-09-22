<?php

namespace App\Services\AyosPush;

use App\Models\WhatsappSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Client of the AyosPush public API v1, authenticated with the API key and
 * secret of one application.
 *
 * AyosPush tokens live one hour. They are cached (encrypted) and a fresh one
 * is requested with the key and secret when they expire or get refused.
 * POST /auth/refresh is deliberately never used: it re-authenticates with an
 * empty secret, answers 200 with no token and revokes the current one.
 */
class AyosPushClient
{
    /**
     * Drop a cached token this long before AyosPush expires it.
     */
    private const TOKEN_MARGIN_SECONDS = 60;

    private const MAX_PAGES = 100;

    /**
     * @var array<int, string>|null
     */
    private ?array $scopes = null;

    public function __construct(private readonly WhatsappSetting $settings)
    {
    }

    public static function for(WhatsappSetting $settings): self
    {
        return new self($settings);
    }

    public function baseUrl(): string
    {
        return rtrim((string) config('services.ayospush.base_url'), '/');
    }

    /**
     * Scopes granted to the key, as reported by the last login.
     *
     * @return array<int, string>|null
     */
    public function scopes(): ?array
    {
        return $this->scopes;
    }

    /**
     * Bearer token for the application's key, from the cache unless $fresh.
     */
    public function authenticate(bool $fresh = false): string
    {
        $cacheKey = $this->tokenCacheKey();

        if (! $fresh && ($cached = $this->cachedToken($cacheKey))) {
            $this->scopes = $cached['scopes'];

            return $cached['token'];
        }

        $response = $this->send(fn (PendingRequest $http) => $http->post('/auth/login', [
            'api_key' => (string) $this->settings->api_key,
            'api_secret' => (string) $this->settings->api_secret,
        ]));

        if (! $response->successful()) {
            throw $this->exceptionFrom($response);
        }

        $data = $response->json('data');
        $data = is_array($data) ? $data : [];
        $token = $data['token'] ?? null;

        if (! is_string($token) || $token === '') {
            // A revoked or expired scoped key gets 200 "Authentication
            // successful" with the reason in data.error and no token.
            throw new AyosPushException(
                'AyosPush rejected the API credentials: ' . ($data['error'] ?? 'no token was returned.'),
                401,
            );
        }

        $this->scopes = isset($data['scopes']) && is_array($data['scopes']) ? array_values($data['scopes']) : null;

        $ttl = (int) ($data['expires_in_seconds'] ?? 3600) - self::TOKEN_MARGIN_SECONDS;
        if ($ttl > 0) {
            Cache::put($cacheKey, Crypt::encryptString(json_encode([
                'token' => $token,
                'scopes' => $this->scopes,
            ])), $ttl);
        }

        return $token;
    }

    public function forgetToken(): void
    {
        Cache::forget($this->tokenCacheKey());
    }

    /**
     * GET /facebook-config: Meta business, WABA accounts and phone numbers.
     *
     * @return array<string, mixed>
     */
    public function facebookConfig(): array
    {
        return $this->data($this->authorized(fn (PendingRequest $http) => $http->get('/facebook-config')));
    }

    /**
     * One page of GET /templates.
     *
     * @param  array<string, mixed>  $query
     * @return array{templates: array<int, array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function templates(array $query = []): array
    {
        $data = $this->data($this->authorized(fn (PendingRequest $http) => $http->get('/templates', $query)));

        return [
            'templates' => array_values(array_filter($data['templates'] ?? [], 'is_array')),
            'pagination' => is_array($data['pagination'] ?? null) ? $data['pagination'] : [],
        ];
    }

    /**
     * Every template the key can see, all pages together.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allTemplates(): array
    {
        $templates = [];
        $page = 1;

        do {
            $result = $this->templates(['per_page' => 100, 'page' => $page]);
            array_push($templates, ...$result['templates']);
            $lastPage = (int) ($result['pagination']['last_page'] ?? 1);
            $page++;
        } while ($page <= $lastPage && $page <= self::MAX_PAGES);

        return $templates;
    }

    /**
     * GET /templates/{id}.
     *
     * @return array<string, mixed>
     */
    public function template(int $id): array
    {
        return $this->data($this->authorized(fn (PendingRequest $http) => $http->get("/templates/{$id}")));
    }

    /**
     * POST /templates. AyosPush stores the template, renames it and submits it
     * to Meta when submit_for_approval is true.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createTemplate(array $payload): array
    {
        $timeout = (int) config('services.ayospush.submit_timeout', 60);

        return $this->data($this->authorized(
            fn (PendingRequest $http) => $http->timeout($timeout)->post('/templates', $payload)
        ));
    }

    /**
     * POST /templates/upload-media. Returns the public media_url to put in a
     * HEADER component.
     *
     * @return array<string, mixed>
     */
    public function uploadMedia(UploadedFile $file, string $type): array
    {
        $contents = (string) file_get_contents($file->getRealPath());
        $name = $file->getClientOriginalName() ?: ('header.' . ($file->guessExtension() ?: 'bin'));
        $mime = $file->getMimeType() ?: 'application/octet-stream';

        return $this->data($this->authorized(
            fn (PendingRequest $http) => $http
                ->timeout((int) config('services.ayospush.submit_timeout', 60))
                ->attach('file', $contents, $name, ['Content-Type' => $mime])
                ->post('/templates/upload-media', ['type' => $type])
        ));
    }

    /**
     * Run an authenticated call, logging in again once when the token is
     * refused (expired early, revoked, or AyosPush restarted).
     *
     * @param  callable(PendingRequest): Response  $call
     */
    private function authorized(callable $call): Response
    {
        $token = $this->authenticate();
        $response = $this->send(fn (PendingRequest $http) => $call($http->withToken($token)));

        if ($response->status() === 401) {
            $this->forgetToken();
            $token = $this->authenticate(true);
            $response = $this->send(fn (PendingRequest $http) => $call($http->withToken($token)));
        }

        if (! $response->successful()) {
            throw $this->exceptionFrom($response);
        }

        return $response;
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call): Response
    {
        // JSON is the default body format. Forcing the Content-Type here
        // (asJson) would also label multipart uploads as JSON.
        $http = Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout((int) config('services.ayospush.timeout', 20))
            ->withUserAgent('AninfPush');

        try {
            return $call($http);
        } catch (ConnectionException $e) {
            throw new AyosPushException('AyosPush could not be reached: ' . $e->getMessage(), 0, previous: $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Response $response): array
    {
        $data = $response->json('data');

        return is_array($data) ? $data : [];
    }

    private function exceptionFrom(Response $response): AyosPushException
    {
        $status = $response->status();
        $body = $response->json();
        $body = is_array($body) ? $body : [];

        // AyosPush puts the detail in `error` (controllers) or in `message`
        // behind a generic `error` label (auth, scope and billing middleware).
        $error = is_string($body['error'] ?? null) ? $body['error'] : null;
        $message = is_string($body['message'] ?? null) ? $body['message'] : null;
        $generic = ['Unauthorized', 'Forbidden', 'Payment Required'];

        $detail = $error !== null && ! in_array($error, $generic, true)
            ? $error
            : ($message ?? $error ?? "HTTP {$status}");

        $requiredScope = is_string($body['required_scope'] ?? null) ? $body['required_scope'] : null;
        $retryAfter = $response->header('Retry-After') !== '' ? (int) $response->header('Retry-After') : null;

        $prefix = match (true) {
            $status === 401 => 'AyosPush rejected the API credentials',
            $status === 402 => 'AyosPush balance or quota exhausted',
            $status === 403 => 'The AyosPush API key is not allowed to do this',
            $status === 404 => 'Not found on AyosPush',
            $status === 429 => 'AyosPush rate limit reached',
            $status >= 500 => 'AyosPush server error',
            default => 'AyosPush refused the request',
        };

        $text = "{$prefix}: {$detail}";
        if ($requiredScope !== null) {
            $text .= " (missing scope: {$requiredScope})";
        }
        if ($status === 429 && $retryAfter) {
            $text .= " Retry in {$retryAfter} s.";
        }

        $errors = is_array($body['errors'] ?? null) ? $body['errors'] : [];

        return new AyosPushException($text, $status, $errors, $requiredScope, $retryAfter);
    }

    /**
     * @return array{token: string, scopes: array<int, string>|null}|null
     */
    private function cachedToken(string $cacheKey): ?array
    {
        $cached = Cache::get($cacheKey);

        if (! is_string($cached)) {
            return null;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($cached), true);
        } catch (DecryptException) {
            Cache::forget($cacheKey);

            return null;
        }

        if (! is_array($decoded) || ! is_string($decoded['token'] ?? null)) {
            return null;
        }

        return ['token' => $decoded['token'], 'scopes' => $decoded['scopes'] ?? null];
    }

    /**
     * Depends on the credentials themselves, so changing the key or the secret
     * never reuses a token issued for the previous ones.
     */
    private function tokenCacheKey(): string
    {
        return 'ayospush:token:' . hash('sha256', implode('|', [
            $this->baseUrl(),
            (string) $this->settings->api_key,
            (string) $this->settings->api_secret,
        ]));
    }
}
