<?php

$frontendUrl = env('FRONTEND_URL', 'http://localhost:5173');
$allowedOrigins = env('CORS_ALLOWED_ORIGINS', $frontendUrl);

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | SPA cookie authentication requires explicit origins and credentials.
    | Do not use a wildcard origin while supports_credentials is true.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) $allowedOrigins),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
