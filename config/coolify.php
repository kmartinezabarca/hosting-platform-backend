<?php

return [
    // Sin default: una instalación sin COOLIFY_URL debe fallar de forma
    // explícita, no apuntar silenciosamente a la infraestructura de prod.
    'base_url' => env('COOLIFY_URL'),
    'api_token' => env('COOLIFY_API_TOKEN'),
    'team_id' => env('COOLIFY_TEAM_ID', '0'),
    'server_uuid' => env('COOLIFY_SERVER_UUID'),
    'verify_ssl' => env('COOLIFY_VERIFY_SSL', true),

    // IP pública/destino de los registros A de DNS para sitios de hosting
    // (Cloudflare). Sin default por la misma razón que base_url.
    'hosting_dns_ip' => env('COOLIFY_HOSTING_DNS_IP'),

    // Dominio base para los subdominios automáticos de hosting:
    // {subdomain}.{hosting_base_domain}. En producción = rokeindustries.com;
    // en desarrollo se sobreescribe a rokeindustries.dev vía HOSTING_BASE_DOMAIN.
    // Por defecto se alinea con la zona de Cloudflare para que el registro A
    // del subdominio caiga en la zona correcta (de lo contrario el DNS falla).
    'hosting_base_domain' => env('HOSTING_BASE_DOMAIN', env('CLOUDFLARE_ZONE_NAME', 'rokeindustries.com')),

    // Host alcanzable desde el backend de Laravel para el gestor de base de datos
    // NATIVO del portal. La DB corre en un contenedor del nodo Coolify (Ryzen); su
    // host interno de Docker NO es accesible desde el server de Laravel, así que el
    // backend conecta a este host (la IP/hostname de Tailscale del nodo) + el puerto
    // que Coolify publica por base (connection_details.db_public_port). Red privada,
    // nunca expuesto a internet. Sin esto, el gestor responde "no habilitado".
    'db_gateway_host' => env('COOLIFY_DB_GATEWAY_HOST'),

    // Health check que se manda al crear aplicaciones en Coolify. Traefik/Caddy
    // enrutan mejor cuando Coolify sabe cuando el contenedor ya esta listo.
    'health_check' => [
        'enabled' => env('COOLIFY_HEALTH_CHECK_ENABLED', true),
        'path' => env('COOLIFY_HEALTH_CHECK_PATH', '/'),
        'port' => env('COOLIFY_HEALTH_CHECK_PORT'),
        'host' => env('COOLIFY_HEALTH_CHECK_HOST'),
        'method' => env('COOLIFY_HEALTH_CHECK_METHOD', 'GET'),
        'return_code' => (int) env('COOLIFY_HEALTH_CHECK_RETURN_CODE', 200),
        'scheme' => env('COOLIFY_HEALTH_CHECK_SCHEME', 'http'),
        'response_text' => env('COOLIFY_HEALTH_CHECK_RESPONSE_TEXT'),
        'interval' => (int) env('COOLIFY_HEALTH_CHECK_INTERVAL', 30),
        'timeout' => (int) env('COOLIFY_HEALTH_CHECK_TIMEOUT', 10),
        'retries' => (int) env('COOLIFY_HEALTH_CHECK_RETRIES', 3),
        'start_period' => (int) env('COOLIFY_HEALTH_CHECK_START_PERIOD', 30),
    ],
];
