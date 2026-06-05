<?php

$corsAllowedOrigins = static function (): array {
    $rawOrigins = array_filter([
        env('CORS_ALLOWED_ORIGINS'),
        env('FRONTEND_URL'),
        env('ADMIN_FRONTEND_URL'),
        env('PORTAL_FRONTEND_URL'),
        env('APP_FRONTEND_URL'),
        env('ROKEPET_FRONTEND_URL'),
    ]);

    $origins = [];
    foreach ($rawOrigins as $raw) {
        foreach (explode(',', (string) $raw) as $origin) {
            $origin = trim($origin);
            if ($origin !== '') {
                $origins[] = $origin;
            }
        }
    }

    if (env('APP_ENV') !== 'local') {
        $origins = array_merge($origins, [
            'https://admin.rokeindustries.dev',
            'https://app.rokeindustries.dev',
            'https://roke.pet',
            'https://www.roke.pet',
        ]);
    }

    if (env('APP_ENV', 'production') === 'local') {
        $origins = array_merge($origins, [
            'http://localhost:3000',
            'http://localhost:4173',
            'http://localhost:4174',
            'http://localhost:5173',
            'http://localhost:5174',
            'http://localhost:5175',
            'http://127.0.0.1:3000',
            'http://127.0.0.1:4173',
            'http://127.0.0.1:4174',
            'http://127.0.0.1:5173',
            'http://127.0.0.1:5174',
            'http://127.0.0.1:5175',
        ]);
    }

    return array_values(array_unique($origins));
};

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $corsAllowedOrigins(),

    'allowed_origins_patterns' => env('APP_ENV') === 'local' ? [] : [
        '#^https://([a-z0-9-]+\.)?rokeindustries\.dev$#',
        '#^https://([a-z0-9-]+\.)?rokeindustries\.com$#',
        '#^https://([a-z0-9-]+\.)?roke\.pet$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
