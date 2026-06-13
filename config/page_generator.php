<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Generador de páginas con IA (SiteBuilder) — agnóstico de proveedor
    |--------------------------------------------------------------------------
    |
    | El proveedor activo se elige SOLO por env (PAGE_GENERATOR_DRIVER); cambiar
    | de proveedor no toca lógica de negocio. dev → ollama (self-hosted en el
    | Ryzen); prod → claude (de pago, reutiliza app/Support/Anthropic). Las
    | API keys nunca viven aquí: se leen de env por entorno.
    |
    */

    'driver' => env('PAGE_GENERATOR_DRIVER', 'ollama'),

    // Dominio público donde el backend SIRVE las páginas publicadas (Opción A).
    // DEBE ser un dominio separado y SIN cookies (p.ej. https://rokeindustries.app),
    // nunca el del api/app: las páginas son HTML de usuario y no deben compartir
    // origen con la sesión Sanctum. Default a APP_URL para no romper en local.
    'public_base' => env('SITE_BUILDER_PUBLIC_BASE', env('APP_URL')),

    // La generación LLM es lenta (más aún self-hosted): timeout amplio.
    'timeout' => (int) env('PAGE_GENERATOR_TIMEOUT', 120),

    // Ollama corre en el Ryzen (roke-ryzen-01), NO en el Mac Mini. Sin API key.
    // OLLAMA_BASE_URL apunta al Ryzen por Tailscale, p.ej. http://100.x.x.x:11434
    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL'),
        'model'    => env('OLLAMA_MODEL', 'llama3.1'),
    ],

    // Claude (fase 3): la API key vive en config/anthropic.php (ANTHROPIC_API_KEY),
    // aquí solo el modelo/limite específicos de generación de páginas.
    'claude' => [
        'model'      => env('PAGE_GEN_CLAUDE_MODEL', 'claude-sonnet-4-6'),
        'max_tokens' => (int) env('PAGE_GEN_CLAUDE_MAX_TOKENS', 8000),
    ],
];
