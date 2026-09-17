<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Signing secret
    |--------------------------------------------------------------------------
    |
    | Secret used to sign the HS256 access tokens. Falls back to APP_KEY so a
    | fresh install works out of the box; set JWT_SECRET in production.
    |
    */
    'secret' => env('JWT_SECRET', env('APP_KEY')),

    'algo' => env('JWT_ALGO', 'HS256'),

    /*
    |--------------------------------------------------------------------------
    | Lifetimes
    |--------------------------------------------------------------------------
    |
    | access_ttl  : lifetime of the JWT access token, in minutes.
    | refresh_ttl : lifetime of the opaque refresh token, in minutes.
    |
    */
    'access_ttl' => (int) env('JWT_ACCESS_TTL', 60),
    'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 60 * 24 * 14),

    /*
    |--------------------------------------------------------------------------
    | Claims
    |--------------------------------------------------------------------------
    */
    'issuer' => env('JWT_ISSUER', env('APP_URL', 'http://localhost')),
    'audience' => env('JWT_AUDIENCE', 'aninfpush'),

    /*
    | Clock skew tolerance, in seconds.
    */
    'leeway' => (int) env('JWT_LEEWAY', 10),
];
