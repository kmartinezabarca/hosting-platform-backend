<?php

return [
    'base_url'    => env('COOLIFY_URL', 'http://100.124.151.68:8000'),
    'api_token'   => env('COOLIFY_API_TOKEN'),
    'team_id'     => env('COOLIFY_TEAM_ID', '0'),
    'server_uuid' => env('COOLIFY_SERVER_UUID'),
    'verify_ssl'  => env('COOLIFY_VERIFY_SSL', true),
];
