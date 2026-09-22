<?php

namespace Tests\Concerns;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * A fake AyosPush public API v1. Every answer mirrors what the AyosPush
 * controllers and middleware actually return (success/data/message on
 * success, success/error[/errors] or error/message on failure).
 *
 * Routes are keyed "METHOD /path" (path after /api/v1, no query string, `*`
 * allowed) and checked in order: put specific ones first.
 */
trait FakesAyosPush
{
    protected string $ayosPushUrl = 'https://ayospush.test/api/v1';

    protected string $ayosPushToken = 'ayospush_test_token';

    /**
     * @param  array<string, callable(Request): mixed>  $routes  overrides, merged over the defaults
     */
    protected function fakeAyosPush(array $routes = []): void
    {
        config(['services.ayospush.base_url' => $this->ayosPushUrl]);

        $routes = $routes + $this->defaultAyosPushRoutes();

        Http::fake(function (Request $request) use ($routes) {
            $key = $request->method() . ' ' . $this->ayosPushPath($request);

            foreach ($routes as $pattern => $respond) {
                if (Str::is($pattern, $key)) {
                    return $respond($request);
                }
            }

            return Http::response(['success' => false, 'error' => "No fake AyosPush route for {$key}"], 599);
        });
    }

    /**
     * @return array<string, callable(Request): mixed>
     */
    protected function defaultAyosPushRoutes(): array
    {
        return [
            'POST /auth/login' => fn () => $this->ayosPushLoginResponse(),
            'GET /facebook-config' => fn () => Http::response([
                'success' => true,
                'data' => [
                    'meta_business_id' => '998877665544',
                    'app_id' => '123456789',
                    'connection_status' => 'active',
                    'connected_at' => '2026-09-01T10:00:00+00:00',
                    'waba_accounts' => [[
                        'id' => 7,
                        'waba_id' => '102030405060708',
                        'name' => 'ANINF',
                        'status' => 'active',
                        'verification_status' => 'verified',
                        'account_type' => null,
                        'phone_numbers_count' => 1,
                        'templates_count' => 3,
                    ]],
                    'phone_numbers' => [[
                        'phone_number_id' => '111222333444555',
                        'display_phone_number' => '+241 77 00 00 00',
                        'verified_name' => 'ANINF',
                        'quality_rating' => 'GREEN',
                        'messaging_limit_tier' => 'TIER_1K',
                        'status' => 'connected',
                        'waba_account_id' => 7,
                    ]],
                ],
                'message' => 'Success',
            ]),
        ];
    }

    /**
     * @param  array<int, string>  $scopes
     */
    protected function ayosPushLoginResponse(array $scopes = ['*'], ?string $token = null): mixed
    {
        return Http::response([
            'success' => true,
            'data' => [
                'token' => $token ?? $this->ayosPushToken,
                'expires_at' => now()->addHour()->toIso8601String(),
                'expires_in_seconds' => 3600,
                'scopes' => $scopes,
            ],
            'message' => 'Authentication successful',
        ]);
    }

    /**
     * A template as GET /v1/templates lists it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function ayosPushListedTemplate(array $overrides = []): array
    {
        return array_merge([
            'id' => 55,
            'name' => 'order_confirmation_biz3_0922101530',
            'display_name' => 'Order confirmation',
            'category' => 'UTILITY',
            'language' => 'fr',
            'status' => 'APPROVED',
            'facebook_template_id' => '1234567890',
            'variables_count' => 2,
            'has_header_media' => false,
            'header_format' => 'TEXT',
            'body_preview' => 'Bonjour {{1}}, votre commande {{2}} est confirmée.',
            'components' => [
                ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Commande confirmée'],
                ['type' => 'BODY', 'text' => 'Bonjour {{1}}, votre commande {{2}} est confirmée.', 'example' => ['body_text' => [['Awa', 'CMD-001']]]],
                ['type' => 'FOOTER', 'text' => 'Merci de votre confiance'],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Suivre', 'url' => 'https://example.test/track']]],
            ],
        ], $overrides);
    }

    /**
     * @param  array<int, array<string, mixed>>  $templates
     */
    protected function ayosPushTemplatesPage(array $templates, int $page = 1, int $lastPage = 1): mixed
    {
        return Http::response([
            'success' => true,
            'data' => [
                'templates' => $templates,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => 100,
                    'total' => count($templates),
                    'last_page' => $lastPage,
                ],
            ],
            'message' => 'Success',
        ]);
    }

    protected function ayosPushError(int $status, string $error, array $extra = []): mixed
    {
        return Http::response(['success' => false, 'error' => $error] + $extra, $status);
    }

    /**
     * Requests AyosPush received, as "METHOD /path".
     *
     * @return Collection<int, Request>
     */
    protected function ayosPushRequests(?string $pattern = null): Collection
    {
        return collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $request) => $pattern === null
                || Str::is($pattern, $request->method() . ' ' . $this->ayosPushPath($request)))
            ->values();
    }

    /**
     * Query string of a request (GET requests carry no body to read it from).
     *
     * @return array<string, mixed>
     */
    protected function ayosPushQuery(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }

    private function ayosPushPath(Request $request): string
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        return Str::after($path, (string) parse_url($this->ayosPushUrl, PHP_URL_PATH)) ?: '/';
    }
}
