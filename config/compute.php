<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Plano de cómputo — orquestador y dominios
    |--------------------------------------------------------------------------
    */

    // Zona wildcard para subdominios gratuitos de apps: {project}-{env}-{hash}.roke.app
    // El registro *.roke.app apunta al edge (Traefik de Coolify) en Cloudflare.
    'app_domain' => env('COMPUTE_APP_DOMAIN', 'roke.app'),

    // Intervalo de re-encolado cuando un paso queda pendiente (polling de builds).
    'deploy_poll_seconds' => (int) env('COMPUTE_DEPLOY_POLL_SECONDS', 8),

    // Tope de ejecuciones del job por orquestación — corta sagas desbocadas.
    'max_orchestration_attempts' => (int) env('COMPUTE_MAX_ORCH_ATTEMPTS', 150),

    'queues' => [
        'provisioning' => 'provisioning',
        'deployments'  => 'deployments',
    ],

    // Límites de spec hasta que el enforcement por plan llegue (mes 2).
    'limits' => [
        'ram_mb_min' => 256,
        'ram_mb_max' => (int) env('COMPUTE_RAM_MB_MAX', 4096),
    ],
];
