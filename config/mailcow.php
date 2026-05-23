<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Mailcow — Correos empresariales para servicios de hosting
    |--------------------------------------------------------------------------
    |
    | Mailcow es la plataforma de correo self-hosted que gestiona los buzones
    | de los clientes de hosting. Cada servicio de hosting puede tener una
    | o más cuentas de correo asociadas a su dominio.
    |
    | Documentación: https://mailcow.github.io/mailcow-dockerized-docs/
    | API: https://<host>/api/v1/
    |
    */

    'base_url'     => env('MAILCOW_BASE_URL', ''),
    'api_key'      => env('MAILCOW_API_KEY', ''),

    // Quota por defecto en MB para nuevos buzones
    'default_quota_mb' => (int) env('MAILCOW_DEFAULT_QUOTA_MB', 500),

    // Máximo de buzones por dominio (0 = ilimitado)
    'max_mailboxes_per_domain' => (int) env('MAILCOW_MAX_MAILBOXES', 10),

    // Timeout en segundos para las llamadas HTTP
    'timeout' => 15,
];
