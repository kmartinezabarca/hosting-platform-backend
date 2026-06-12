<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Anthropic (Claude) — IA de soporte
    |--------------------------------------------------------------------------
    | La llave vive SÓLO en el backend (.env) y nunca se expone al frontend.
    | Las llamadas a la API se hacen exclusivamente desde el servidor.
    */
    'api_key'  => env('ANTHROPIC_API_KEY'),
    'base_url' => rtrim(env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'), '/'),
    'version'  => env('ANTHROPIC_VERSION', '2023-06-01'),

    // Modelo por defecto para el asistente de soporte: rápido y económico.
    'model'      => env('ANTHROPIC_SUPPORT_MODEL', 'claude-haiku-4-5'),
    'max_tokens' => (int) env('ANTHROPIC_SUPPORT_MAX_TOKENS', 700),
    'timeout'    => (int) env('ANTHROPIC_TIMEOUT', 30),

    // ── Agente de plataforma (plano de cómputo, blueprint doc 03) ────────────
    'agent' => [
        'enabled' => env('PLATFORM_AI_AGENT_ENABLED', true),

        // Loop de herramientas: razonamiento sobre recursos/deploys.
        'model'      => env('PLATFORM_AI_AGENT_MODEL', 'claude-sonnet-4-6'),
        'max_tokens' => (int) env('PLATFORM_AI_AGENT_MAX_TOKENS', 1500),

        // Tope de iteraciones del loop tool-use por mensaje del usuario.
        'max_iterations' => (int) env('PLATFORM_AI_AGENT_MAX_ITERATIONS', 6),

        // Historial enviado por turno.
        'history_limit' => (int) env('PLATFORM_AI_AGENT_HISTORY_LIMIT', 16),
    ],

    // Clasificación/explicación de fallas de deploy (barato y rápido).
    'diagnose' => [
        'model'      => env('PLATFORM_AI_DIAGNOSE_MODEL', 'claude-haiku-4-5'),
        'max_tokens' => (int) env('PLATFORM_AI_DIAGNOSE_MAX_TOKENS', 500),
    ],

    'support' => [
        // Interruptor maestro de la IA. Si está apagado (o falta la llave), el
        // chat sigue funcionando: cada mensaje del cliente escala directo a un
        // humano en vez de auto-responder. NUNCA se inventan respuestas falsas.
        'enabled' => env('ROKEPET_AI_SUPPORT_ENABLED', true),

        // Umbral de confianza: por debajo de esto, la IA escala a un humano.
        'confidence_threshold' => (float) env('ROKEPET_AI_CONFIDENCE_THRESHOLD', 0.55),

        // Nº de artículos de la KB que se inyectan como contexto por turno.
        'kb_top_k' => (int) env('ROKEPET_AI_KB_TOP_K', 3),

        // Nº de mensajes previos que se envían como historial a la IA.
        'history_limit' => (int) env('ROKEPET_AI_HISTORY_LIMIT', 12),

        // Temas que SIEMPRE escalan a humano antes de llamar a la IA (defensa
        // en profundidad; la IA también puede escalar por su cuenta).
        'force_escalation_keywords' => [
            'reembolso', 'reembolsar', 'devolucion', 'devolución', 'me cobraron',
            'cobro', 'cobraron de mas', 'fraude', 'estafa', 'demanda', 'legal',
            'abogado', 'cancelar cuenta', 'eliminar cuenta', 'borrar cuenta',
            'hackear', 'hackearon', 'robaron mi cuenta', 'no puedo entrar',
            'urgente', 'emergencia', 'envenen', 'intoxic', 'sangre', 'convuls',
            'hablar con una persona', 'hablar con un humano', 'agente humano',
            'quiero hablar con alguien', 'persona real',
        ],
    ],
];
