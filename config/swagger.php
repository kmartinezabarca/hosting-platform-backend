<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Documentación de la API (OpenAPI / Swagger)
    |--------------------------------------------------------------------------
    |
    | La especificación OpenAPI se genera en App\Http\Controllers\ApiDocsController
    | y se sirve con Swagger UI. En producción la documentación está
    | deshabilitada por defecto; actívala explícitamente con SWAGGER_ENABLED=true.
    |
    */

    // Habilitar la documentación. En local/staging va activa; en producción
    // solo si se define SWAGGER_ENABLED=true de forma explícita.
    'enabled' => (bool) env('SWAGGER_ENABLED', env('APP_ENV') !== 'production'),

    'title'       => env('SWAGGER_TITLE', 'ROKE Industries API'),
    'version'     => env('SWAGGER_VERSION', '1.0.0'),
    'description' => 'API REST de la plataforma de hosting de ROKE Industries. '
        . 'La mayoría de los endpoints requieren autenticación con un token '
        . 'Bearer (Laravel Sanctum). Usa el botón "Authorize" para fijarlo.',

    'contact' => [
        'name'  => 'Soporte ROKE Industries',
        'email' => env('SWAGGER_CONTACT_EMAIL', 'soporte@rokeindustries.com'),
    ],

    // URL base del servidor de la API que verá Swagger UI.
    'server_url' => env('SWAGGER_SERVER_URL', rtrim(env('APP_URL', 'http://localhost:8000'), '/') . '/api'),

    // Rutas (relativas al prefijo /api).
    'routes' => [
        'json' => 'docs',     // GET /api/docs    → JSON OpenAPI
        'ui'   => 'swagger',  // GET /api/swagger → Swagger UI
    ],

    // Esquema de seguridad expuesto en el spec.
    'security_scheme' => 'bearerAuth',
];
