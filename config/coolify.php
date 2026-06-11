<?php

return [
    // Sin default: una instalación sin COOLIFY_URL debe fallar de forma
    // explícita, no apuntar silenciosamente a la infraestructura de prod.
    'base_url'    => env('COOLIFY_URL'),
    'api_token'   => env('COOLIFY_API_TOKEN'),
    'team_id'     => env('COOLIFY_TEAM_ID', '0'),
    'server_uuid' => env('COOLIFY_SERVER_UUID'),
    'verify_ssl'  => env('COOLIFY_VERIFY_SSL', true),

    // IP pública/destino de los registros A de DNS para sitios de hosting
    // (Cloudflare). Sin default por la misma razón que base_url.
    'hosting_dns_ip' => env('COOLIFY_HOSTING_DNS_IP'),
];
