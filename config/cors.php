<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | CORS headers are handled entirely by Nginx to avoid duplicate headers.
    | Laravel's CORS middleware is disabled for API routes.
    |
    | See Nginx config for: Access-Control-Allow-Origin, Allow-Methods,
    | Allow-Headers, Allow-Credentials (both OPTIONS preflight and PHP responses).
    |
    */

    'paths' => ['sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['https://king-ai-gamma.vercel.app'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];