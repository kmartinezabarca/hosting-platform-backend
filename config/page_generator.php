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
