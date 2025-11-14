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
    | No user providers are configured as user management is handled by Keycloak.
    | If you need to load users from database, configure in config/keycloak.php
    |
    */

    'providers' => [
        // No providers - Keycloak manages all users
        // If you need database users, set KEYCLOAK_LOAD_USER_FROM_DATABASE=true
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
        // No password reset - handled by Keycloak
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    */

    'password_timeout' => null,

];
