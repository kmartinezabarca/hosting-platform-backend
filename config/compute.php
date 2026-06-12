<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Plano de cómputo — orquestador y dominios
    |--------------------------------------------------------------------------
    */

    // Zona wildcard para subdominios de apps: {project}-{env}-{hash}.apps.rokeindustries.dev
    // El registro *.apps.rokeindustries.dev apunta al edge (Traefik de Coolify).
    // Cada ambiente puede sobreescribirlo con COMPUTE_APP_DOMAIN.
    'app_domain' => env('COMPUTE_APP_DOMAIN', 'apps.rokeindustries.dev'),

    // Intervalo de re-encolado cuando un paso queda pendiente (polling de builds).
    'deploy_poll_seconds' => (int) env('COMPUTE_DEPLOY_POLL_SECONDS', 8),

    // Tope de ejecuciones del job por orquestación — corta sagas desbocadas.
    'max_orchestration_attempts' => (int) env('COMPUTE_MAX_ORCH_ATTEMPTS', 150),

    'queues' => [
        'provisioning' => 'provisioning',
        'deployments'  => 'deployments',
    ],

    // Ambientes preview de PR: efímeros, con TTL de seguridad por si el webhook
    // de cierre nunca llega (el scheduler los barre al expirar).
    'previews' => [
        'enabled'  => (bool) env('COMPUTE_PREVIEWS_ENABLED', true),
        'ttl_days' => (int) env('COMPUTE_PREVIEWS_TTL_DAYS', 14),
    ],

    // Cotas absolutas de spec (validación de forma). El tope efectivo por
    // recurso lo baja además el plan del equipo (ver `plans`).
    'limits' => [
        'ram_mb_min' => 256,
        'ram_mb_max' => (int) env('COMPUTE_RAM_MB_MAX', 4096),
    ],

    // Límites por tier comercial (PlanTier). Se resuelven aquí para ajustarlos
    // sin migración. `max_resources` = recursos activos por equipo (apps + data
    // stores + game servers); `ram_mb_max` = tope de RAM por recurso. El
    // enforcement vive en Compute\Plans\PlanLimits y corre al crear recursos.
    'plans' => [
        'free'    => ['max_resources' => 2,   'ram_mb_max' => 512],
        'starter' => ['max_resources' => 5,   'ram_mb_max' => 1024],
        'pro'     => ['max_resources' => 15,  'ram_mb_max' => 2048],
        'team'    => ['max_resources' => 40,  'ram_mb_max' => 4096],
        'agency'  => ['max_resources' => 150, 'ram_mb_max' => 4096],
    ],
];
