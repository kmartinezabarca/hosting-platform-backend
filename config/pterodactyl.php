<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Pterodactyl Panel
    |--------------------------------------------------------------------------
    | Documentación API: https://dashflo.net/docs/api/pterodactyl/v1/
    |
    | 1. Instala Pterodactyl en tu servidor: https://pterodactyl.io
    | 2. Ve a Admin → Application API → Create API Key
    | 3. Asigna permisos: Users (r/w), Servers (r/w), Nodes (r), Nests (r)
    */

    // URL base del panel (sin slash final). Ej: https://panel.tudominio.com
    'base_url'  => env('PTERODACTYL_URL', ''),

    // Application API key — para gestión de servidores/usuarios (empieza con "ptla_")
    'api_key'        => env('PTERODACTYL_API_KEY', ''),

    // Client API key — para power actions y métricas en tiempo real (empieza con "ptlc_")
    // Créalo en el panel: Account → API Credentials (con permisos de la cuenta admin)
    'client_api_key' => env('PTERODACTYL_CLIENT_API_KEY', ''),

    // IP del relay/VPS donde Wings recibe conexiones externas.
    // Usada para registros A de Bedrock y como IP de display cuando no hay SRV.
    'relay_ip' => env('PTERODACTYL_RELAY_IP', '178.156.225.26'),

    // Nodo por defecto para aprovisionamiento.
    // En DEV apunta al nodo 2 (Mac Mini), en PROD null = autoselect.
    'default_node' => env('PTERODACTYL_DEFAULT_NODE') ? (int) env('PTERODACTYL_DEFAULT_NODE') : null,

    // URL interna de Wings (Tailscale/local). Pterodactyl devuelve URLs de WebSocket
    // con esta base; se reescriben a la URL pública antes de enviarse al cliente.
    'wings_internal_url' => env('PTERODACTYL_WINGS_INTERNAL_URL', 'http://100.94.93.51:8080'),

    // URL pública de Wings (dominio o IP externa accesible desde el navegador).
    'wings_public_url'   => env('PTERODACTYL_WINGS_PUBLIC_URL', 'https://mc.rokeindustries.com'),

    // Timeout HTTP en segundos
    'timeout'   => 30,

    // Verificar certificado SSL al conectar con el panel.
    // Poner false SOLO en desarrollo con certificado autofirmado.
    // En producción SIEMPRE debe ser true.
    'verify_ssl' => env('PTERODACTYL_VERIFY_SSL', true),

    /*
    |--------------------------------------------------------------------------
    | Defaults de aprovisionamiento
    |--------------------------------------------------------------------------
    | Se usan cuando el ServicePlan no especifica valores propios.
    */
    'defaults' => [
        'limits' => [
            'memory'  => 1024,   // MB
            'swap'    => 512,    // MB
            'disk'    => 5120,   // MB (5 GB)
            'io'      => 500,    // I/O weight (100-1000)
            'cpu'     => 100,    // % (100 = 1 core)
            'threads' => null,
        ],
        'feature_limits' => [
            'databases'   => 1,
            'backups'     => 2,
            'allocations' => 1,
        ],
    ],
];
