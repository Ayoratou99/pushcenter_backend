<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Configuration
    |--------------------------------------------------------------------------
    |
    | ⚠️ IMPORTANT: This application uses Keycloak for authentication.
    |
    | All authentication is handled via JWT tokens validated by Keycloak
    | using the robsontenorio/laravel-keycloak-guard package.
    |
    | Package: https://github.com/robsontenorio/laravel-keycloak-guard
    |
    | See:
    | - config/keycloak.php for Keycloak Guard configuration
    | - AUTHENTICATION.md for complete documentation
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | The default guard is 'keycloak-guard' which validates JWT tokens.
    |
    */

    'defaults' => [
        'guard' => 'api',
        'passwords' => 'users',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | keycloak-guard: Validates JWT tokens from Keycloak
    |                 No local user provider needed
    |
    */

    'guards' => [
        'api' => [
            'driver' => 'keycloak',
            'provider' => "users",
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | The 'users' provider is required by Laravel Keycloak Guard.
    | Even though we don't load from database, the User model must exist.
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | Password reset is handled by Keycloak, not by this application.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    */

    'password_timeout' => null,

];
