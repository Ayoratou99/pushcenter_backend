<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Keycloak Server URL
    |--------------------------------------------------------------------------
    |
    | The full URL to your Keycloak server including the scheme (http/https).
    | Example: https://keycloak.example.com
    |
    */
    'realm_public_key' => env('KEYCLOAK_REALM_PUBLIC_KEY', "MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAtSDPJFc96kyMAR3itsxHx5IYYv+6YYjPSR38Kfz6vIBV9QAWh2/uF6jqMxgwlg3ou4NImnO/x4+QJA65DOGKqHWQ0pMZDt5nPx2ClSHQHA0CaS6SQ7CFQojfXHq1Lk6b7/FaRIt0BjtXroP5B0sPYcHeJz/CHDO2ECGtGw8uVXtjBlX49vlxyrC5CovtIN/8HEwXYFjhYqIEe5iX17WdcMTRjTrRQQBg07Gz7RZNPOPFunNZvl+3c8+3oo40m9G2jduXnOWdDKXy7vm2rjg93w8JFTA8K0b//5t6gVYU79Azee0Nuvwe6HqQ2GICaQ9UBdKn+Vbq5tCm/jQKWNWhDwIDAQAB"),

    'load_user_from_database' => env('KEYCLOAK_LOAD_USER_FROM_DATABASE', false),

    'user_provider_custom_retrieve_method' => null,

    'user_provider_credential' => env('KEYCLOAK_USER_PROVIDER_CREDENTIAL', 'username'),

    'token_principal_attribute' => env('KEYCLOAK_TOKEN_PRINCIPAL_ATTRIBUTE', 'preferred_username'),

    'append_decoded_token' => env('KEYCLOAK_APPEND_DECODED_TOKEN', false),

    'allowed_resources' => env('KEYCLOAK_ALLOWED_RESOURCES', ''),

    /*
    |--------------------------------------------------------------------------
    | Ignore Resources Validation
    |--------------------------------------------------------------------------
    |
    | Set to true to disable resource_access validation entirely.
    | When true, the guard will not check if the user has access to allowed_resources.
    |
    */
    'ignore_resources_validation' => env('KEYCLOAK_IGNORE_RESOURCES_VALIDATION', true),

    /*
    |--------------------------------------------------------------------------
    | Leeway (seconds)
    |--------------------------------------------------------------------------
    |
    | You can add a leeway to account for clock skew between signing and
    | verifying servers. Recommended value is 60 seconds if you face issues.
    |
    */
    'leeway' => env('KEYCLOAK_LEEWAY', 0),

    /*
    |--------------------------------------------------------------------------
    | Token Input Key
    |--------------------------------------------------------------------------
    |
    | By default the package looks for a Bearer token in the Authorization header.
    | Optionally, you can specify a request parameter to get the token from.
    |
    | Example: if set to 'api_token', the guard will check for:
    | GET /api/endpoint?api_token=xxx
    | POST /api/endpoint with body: {"api_token": "xxx"}
    |
    */
    'input_key' => env('KEYCLOAK_TOKEN_INPUT_KEY', null),

    /*
    |--------------------------------------------------------------------------
    | Preferred Username (for testing)
    |--------------------------------------------------------------------------
    |
    | When using actingAsKeycloakUser() in tests without load_user_from_database,
    | this value will be used as the preferred_username claim.
    |
    */
    'preferred_username' => env('KEYCLOAK_PREFERRED_USERNAME', 'admin'),
];
