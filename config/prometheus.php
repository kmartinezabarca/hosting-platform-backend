<?php

return [
    'enabled' => env('PROMETHEUS_ENABLED', true),

    'urls' => [
        'metrics' => '/metrics',
    ],

    'middleware' => [],

    'cache' => null,
];
