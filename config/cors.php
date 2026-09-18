<?php

/**
 * Origins allowed to call the API from a browser.
 *
 * FRONTEND_URL is the management console. CORS_ALLOWED_ORIGINS adds any extra
 * origin (a staging console, a customer dashboard calling the application API
 * from the browser...), comma separated.
 *
 * An origin is a scheme, a host and an optional port: no trailing slash and no
 * path, otherwise browsers never match it.
 */
$origins = collect([
    env('FRONTEND_URL'),
    ...explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
])
    ->map(fn ($origin) => rtrim(trim((string) $origin), '/'))
    ->filter()
    ->unique()
    ->values()
    ->all();

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | Only the declared origins may call the API from a browser. Server to
    | server callers (the application API) are unaffected: CORS is a browser
    | mechanism and those requests carry no Origin header.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    // Falls back to "everything" only when nothing is configured, so a fresh
    // install still works; production must set FRONTEND_URL.
    'allowed_origins' => $origins ?: ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'X-CSRF-TOKEN',
    ],

    // Exposed so a browser client can read them from the response.
    'exposed_headers' => [
        'Content-Disposition',
    ],

    // Preflight result cached by the browser, in seconds.
    'max_age' => (int) env('CORS_MAX_AGE', 3600),

    // Authentication travels in the Authorization header, never in a cookie,
    // so credentialed requests are not needed and stay off.
    'supports_credentials' => false,

];
