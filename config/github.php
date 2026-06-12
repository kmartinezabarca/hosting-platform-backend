<?php

return [
    /*
    |--------------------------------------------------------------------------
    | GitHub App — integración de repositorios (plano de cómputo)
    |--------------------------------------------------------------------------
    |
    | Es una GitHub App (no OAuth App): scopes por repo en la instalación,
    | webhooks a nivel app y tokens de instalación efímeros (1h). La llave
    | privada se inyecta en base64 por env — nunca se persiste en DB.
    |
    */

    'app_id'   => env('GITHUB_APP_ID'),
    'app_slug' => env('GITHUB_APP_SLUG', 'roke-platform'),

    // Llave privada PEM de la App, codificada en base64 para caber en una
    // sola línea de .env: `base64 -w0 roke-platform.private-key.pem`
    'private_key_base64' => env('GITHUB_APP_PRIVATE_KEY_BASE64'),

    // Secreto del webhook (X-Hub-Signature-256).
    'webhook_secret' => env('GITHUB_WEBHOOK_SECRET'),

    'api_base' => rtrim(env('GITHUB_API_BASE', 'https://api.github.com'), '/'),

    'timeout' => (int) env('GITHUB_TIMEOUT', 15),
];
