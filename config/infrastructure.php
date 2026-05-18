<?php

/**
 * Topología estática de la red de ROKE Industries.
 *
 * Campos por región:
 *   id       — identificador único de la región
 *   name     — nombre de ciudad / datacenter
 *   country  — código ISO 3166-1 alpha-2
 *   x        — posición relativa en el mapa (0.0–1.0 horizontal)
 *   y        — posición relativa en el mapa (0.0–1.0 vertical)
 *   node_count      — nodos físicos / VMs activos
 *   avg_latency_ms  — latencia promedio medida desde CDMX
 *   status_key      — clave que corresponde a un service_name en system_status (o null)
 */
return [
    'regions' => [
        [
            'id'             => 'mx-cdx-1',
            'name'           => 'CDMX',
            'country'        => 'MX',
            'x'              => 0.38,
            'y'              => 0.50,
            'node_count'     => 4,
            'avg_latency_ms' => 8,
            'status_key'     => 'mx-cdx-1',
        ],
        [
            'id'             => 'mx-mty-1',
            'name'           => 'Monterrey',
            'country'        => 'MX',
            'x'              => 0.32,
            'y'              => 0.30,
            'node_count'     => 2,
            'avg_latency_ms' => 14,
            'status_key'     => 'mx-mty-1',
        ],
        [
            'id'             => 'us-dfw-1',
            'name'           => 'Dallas',
            'country'        => 'US',
            'x'              => 0.28,
            'y'              => 0.18,
            'node_count'     => 1,
            'avg_latency_ms' => 42,
            'status_key'     => 'us-dfw-1',
        ],
        [
            'id'             => 'us-sfo-2',
            'name'           => 'SF Bay',
            'country'        => 'US',
            'x'              => 0.14,
            'y'              => 0.22,
            'node_count'     => 0,
            'avg_latency_ms' => 88,
            'status_key'     => null,
        ],
        [
            'id'             => 'eu-ams-1',
            'name'           => 'Amsterdam',
            'country'        => 'NL',
            'x'              => 0.70,
            'y'              => 0.16,
            'node_count'     => 1,
            'avg_latency_ms' => 124,
            'status_key'     => 'eu-ams-1',
        ],
        [
            'id'             => 'sa-sao-1',
            'name'           => 'São Paulo',
            'country'        => 'BR',
            'x'              => 0.50,
            'y'              => 0.70,
            'node_count'     => 0,
            'avg_latency_ms' => 56,
            'status_key'     => null,
        ],
    ],
];
