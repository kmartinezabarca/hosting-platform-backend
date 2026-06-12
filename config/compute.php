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
    // `max_members` incluye al owner. free/starter = solo (1); los planes de
    // equipo habilitan colaboradores (blueprint: "teams >1 member" llega aquí).
    'plans' => [
        'free'    => ['max_resources' => 2,   'ram_mb_max' => 512,  'max_members' => 1],
        'starter' => ['max_resources' => 5,   'ram_mb_max' => 1024, 'max_members' => 1],
        'pro'     => ['max_resources' => 15,  'ram_mb_max' => 2048, 'max_members' => 3],
        'team'    => ['max_resources' => 40,  'ram_mb_max' => 4096, 'max_members' => 10],
        'agency'  => ['max_resources' => 150, 'ram_mb_max' => 4096, 'max_members' => 25],
    ],

    // Catálogo de precios de los planes (mes 3 — annual billing). Los montos y
    // los stripe_price_id viven en env para NO hornear precios en el repo: un
    // tier sin precio configurado se expone como "sin precio" (price null), no
    // como gratis. El plan 'free' es realmente $0. Resolución y cálculo del
    // ahorro anual en Compute\Plans\PlanCatalog.
    'billing' => [
        'currency' => env('COMPUTE_BILLING_CURRENCY', 'MXN'),

        // amount = precio del periodo (mensual, o el cobro anual completo) en la
        // moneda anterior; viene de env como string|null y PlanCatalog lo castea
        // a float|null. null = no configurado (NO es gratis). 'free' sí es $0.
        'pricing' => [
            'free' => [
                'monthly' => ['amount' => 0, 'stripe_price_id' => null],
                'annual'  => ['amount' => 0, 'stripe_price_id' => null],
            ],
            'starter' => [
                'monthly' => ['amount' => env('COMPUTE_PRICE_STARTER_MONTHLY'), 'stripe_price_id' => env('COMPUTE_STRIPE_STARTER_MONTHLY')],
                'annual'  => ['amount' => env('COMPUTE_PRICE_STARTER_ANNUAL'),  'stripe_price_id' => env('COMPUTE_STRIPE_STARTER_ANNUAL')],
            ],
            'pro' => [
                'monthly' => ['amount' => env('COMPUTE_PRICE_PRO_MONTHLY'), 'stripe_price_id' => env('COMPUTE_STRIPE_PRO_MONTHLY')],
                'annual'  => ['amount' => env('COMPUTE_PRICE_PRO_ANNUAL'),  'stripe_price_id' => env('COMPUTE_STRIPE_PRO_ANNUAL')],
            ],
            'team' => [
                'monthly' => ['amount' => env('COMPUTE_PRICE_TEAM_MONTHLY'), 'stripe_price_id' => env('COMPUTE_STRIPE_TEAM_MONTHLY')],
                'annual'  => ['amount' => env('COMPUTE_PRICE_TEAM_ANNUAL'),  'stripe_price_id' => env('COMPUTE_STRIPE_TEAM_ANNUAL')],
            ],
            'agency' => [
                'monthly' => ['amount' => env('COMPUTE_PRICE_AGENCY_MONTHLY'), 'stripe_price_id' => env('COMPUTE_STRIPE_AGENCY_MONTHLY')],
                'annual'  => ['amount' => env('COMPUTE_PRICE_AGENCY_ANNUAL'),  'stripe_price_id' => env('COMPUTE_STRIPE_AGENCY_ANNUAL')],
            ],
        ],
    ],

    // Presets de servidores de juego (mes 3 — "más juegos"). Las specs son datos
    // reales del juego (puerto por defecto, RAM recomendada); el egg/nest de
    // Pterodactyl viene de env (sin configurar => el preset no es aprovisionable
    // aún, `available=false`). El flujo de provisión (ProvisionGameServerFlow)
    // se conecta después; este catálogo ya alimenta el selector del wizard.
    'game_presets' => [
        'minecraft' => [
            'name' => 'Minecraft (Java)', 'default_port' => 25565,
            'min_ram_mb' => 2048, 'recommended_ram_mb' => 4096, 'max_players' => 20,
            'egg_id' => env('COMPUTE_GAME_MINECRAFT_EGG'), 'nest_id' => env('COMPUTE_GAME_MINECRAFT_NEST'),
        ],
        'fivem' => [
            'name' => 'FiveM (GTA V)', 'default_port' => 30120,
            'min_ram_mb' => 2048, 'recommended_ram_mb' => 4096, 'max_players' => 48,
            'egg_id' => env('COMPUTE_GAME_FIVEM_EGG'), 'nest_id' => env('COMPUTE_GAME_FIVEM_NEST'),
        ],
        'rust' => [
            'name' => 'Rust', 'default_port' => 28015,
            'min_ram_mb' => 4096, 'recommended_ram_mb' => 8192, 'max_players' => 100,
            'egg_id' => env('COMPUTE_GAME_RUST_EGG'), 'nest_id' => env('COMPUTE_GAME_RUST_NEST'),
        ],
        'palworld' => [
            'name' => 'Palworld', 'default_port' => 8211,
            'min_ram_mb' => 8192, 'recommended_ram_mb' => 16384, 'max_players' => 32,
            'egg_id' => env('COMPUTE_GAME_PALWORLD_EGG'), 'nest_id' => env('COMPUTE_GAME_PALWORLD_NEST'),
        ],
        'valheim' => [
            'name' => 'Valheim', 'default_port' => 2456,
            'min_ram_mb' => 2048, 'recommended_ram_mb' => 4096, 'max_players' => 10,
            'egg_id' => env('COMPUTE_GAME_VALHEIM_EGG'), 'nest_id' => env('COMPUTE_GAME_VALHEIM_NEST'),
        ],
    ],
];
