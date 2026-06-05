<?php

$normalizeReverbOriginHost = static function (string $origin): ?string {
    $origin = trim($origin);
    if ($origin === '') {
        return null;
    }

    if ($origin === '*') {
        return '*';
    }

    if (str_contains($origin, '://')) {
        $host = parse_url($origin, PHP_URL_HOST);
        return $host ? trim($host) : null;
    }

    $origin = preg_replace('/^wss?:\/\//', '', $origin);
    $origin = strtok($origin, '/');

    if ($origin === false || $origin === '') {
        return null;
    }

    // Reverb valida solo el host del Origin, no scheme ni puerto.
    if (! str_starts_with($origin, '[') && str_contains($origin, ':')) {
        $origin = explode(':', $origin, 2)[0];
    }

    return trim($origin, " \t\n\r\0\x0B[]");
};

$reverbAllowedOrigins = static function () use ($normalizeReverbOriginHost): array {
    $rawOrigins = array_filter([
        env('REVERB_ALLOWED_ORIGINS'),
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
                $host = $normalizeReverbOriginHost($origin);
                if ($host) {
                    $origins[] = $host;
                }
            }
        }
    }

    $origins = array_merge($origins, [
        'admin.rokeindustries.dev',
        'app.rokeindustries.dev',
        'rokeindustries.com',
        '*.rokeindustries.com',
        'rokeindustries.dev',
        '*.rokeindustries.dev',
        'roke.pet',
        '*.roke.pet',
    ]);

    if (env('APP_ENV', 'production') === 'local') {
        $origins = array_merge($origins, [
            'localhost',
            '127.0.0.1',
        ]);
    }

    $origins = array_values(array_unique($origins));

    return $origins ?: ['*'];
};

return [

    /*
    |--------------------------------------------------------------------------
    | Default Reverb Server
    |--------------------------------------------------------------------------
    |
    | This option controls the default server used by Reverb to handle
    | incoming messages as well as broadcasting message to all your
    | connected clients. At this time only "reverb" is supported.
    |
    */

    'default' => env('REVERB_SERVER', 'reverb'),

    /*
    |--------------------------------------------------------------------------
    | Reverb Servers
    |--------------------------------------------------------------------------
    |
    | Here you may define details for each of the supported Reverb servers.
    | Each server has its own configuration options that are defined in
    | the array below. You should ensure all the options are present.
    |
    */

    'servers' => [

        'reverb' => [
            'host' => env('REVERB_SERVER_HOST', '0.0.0.0'),
            'port' => env('REVERB_SERVER_PORT', 8080),
            'path' => env('REVERB_SERVER_PATH', ''),
            'hostname' => env('REVERB_HOST'),
            'options' => [
                'tls' => [],
            ],
            'max_request_size' => env('REVERB_MAX_REQUEST_SIZE', 10_000),
            'scaling' => [
                'enabled' => env('REVERB_SCALING_ENABLED', false),
                'channel' => env('REVERB_SCALING_CHANNEL', 'reverb'),
                'server' => [
                    'url' => env('REDIS_URL'),
                    'host' => env('REDIS_HOST', '127.0.0.1'),
                    'port' => env('REDIS_PORT', '6379'),
                    'username' => env('REDIS_USERNAME'),
                    'password' => env('REDIS_PASSWORD'),
                    'database' => env('REDIS_DB', '0'),
                    'timeout' => env('REDIS_TIMEOUT', 60),
                ],
            ],
            'pulse_ingest_interval' => env('REVERB_PULSE_INGEST_INTERVAL', 15),
            'telescope_ingest_interval' => env('REVERB_TELESCOPE_INGEST_INTERVAL', 15),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Reverb Applications
    |--------------------------------------------------------------------------
    |
    | Here you may define how Reverb applications are managed. If you choose
    | to use the "config" provider, you may define an array of apps which
    | your server will support, including their connection credentials.
    |
    */

    'apps' => [

        'provider' => 'config',

        'apps' => [
            [
                'key' => env('REVERB_APP_KEY'),
                'secret' => env('REVERB_APP_SECRET'),
                'app_id' => env('REVERB_APP_ID'),
                'options' => [
                    'host' => env('REVERB_HOST', 'localhost'),
                    'port' => env('REVERB_PORT', 443),
                    'scheme' => env('REVERB_SCHEME', 'https'),
                    'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
                ],
                'allowed_origins' => $reverbAllowedOrigins(),
                'ping_interval' => env('REVERB_APP_PING_INTERVAL', 60),
                'activity_timeout' => env('REVERB_APP_ACTIVITY_TIMEOUT', 30),
                'max_message_size' => env('REVERB_APP_MAX_MESSAGE_SIZE', 10_000),
            ],
        ],

    ],

];
