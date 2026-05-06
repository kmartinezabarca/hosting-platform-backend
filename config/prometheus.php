<?php

return [
    'enabled' => env('PROMETHEUS_ENABLED', true),

    'urls' => [
        'default' => '/metrics',
    ],

    'middleware' => [],

    'cache' => null,
];
