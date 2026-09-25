<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | The Homflo Nuxt SPA authenticates with session cookies, so credentialed
    | cross-origin requests require an explicit allowed origin. A wildcard
    | origin must never be used together with credentials.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout', 'register'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(explode(',', (string) env('FRONTEND_URL', 'http://localhost:3000')))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
