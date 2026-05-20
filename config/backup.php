<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Configuración de Respaldos (Backups)
    |--------------------------------------------------------------------------
    */

    // Disco (config/filesystems.php) donde se almacenan los respaldos.
    'disk' => env('BACKUP_DISK', 'nas'),

    // Subcarpeta raíz dentro del disco.
    'root' => env('BACKUP_ROOT', 'backups'),

    // Días que se conservan los respaldos por defecto antes de purgarse.
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),

    // Tipos de respaldo con etiquetas para la UI.
    'types' => [
        // ── Proyectos internos ──────────────────────────────────────
        'platform'      => 'Backend / BD principal',
        'landing'       => 'Landing page',
        'portal_client' => 'Portal de clientes',
        'portal_admin'  => 'Panel de administración',
        'pet'           => 'ROKE.pet',
        // ── Infraestructura ─────────────────────────────────────────
        'hosting'       => 'Hosting (Coolify)',
        'game_server'   => 'Servidor de juego (Pterodactyl)',
        // ── Clientes ────────────────────────────────────────────────
        'client_files'  => 'Archivos de cliente',
    ],

    // Frecuencias permitidas para programaciones.
    'frequencies' => ['daily', 'weekly', 'monthly', 'cron'],

    // Ruta a mysqldump (para el respaldo de plataforma).
    'mysqldump_path' => env('BACKUP_MYSQLDUMP_PATH', 'mysqldump'),

    /*
    | Configuración por proyecto interno.
    | source_path  → directorio a empaquetar (null = solo BD).
    | db           → clave de conexión en config/database.php,
    |                o 'custom' para usar las variables de entorno propias.
    */
    'projects' => [
        'landing' => [
            'source_path' => env('LANDING_SOURCE_PATH'),
            'db'          => null,  // sin base de datos propia
        ],
        'portal_client' => [
            'source_path' => env('PORTAL_CLIENT_SOURCE_PATH'),
            'db'          => null,
        ],
        'portal_admin' => [
            'source_path' => env('PORTAL_ADMIN_SOURCE_PATH'),
            'db'          => null,
        ],
        'pet' => [
            'source_path' => env('PET_SOURCE_PATH'),
            'db'          => 'roke_pet',  // → conexión "roke_pet" en database.php
        ],
    ],

    /*
    | Subdirectorios del disco NAS donde viven respaldos (propios y pre-existentes).
    | Se usan para limitar el escaneo (scan-nas) y evitar recorrer el NAS entero.
    |
    | Estructura real detectada en /mnt/backups:
    |   platform/          → respaldos creados por este sistema (plataforma)
    |   landing/           → respaldos creados por este sistema (landing)
    |   portal_client/     → respaldos creados por este sistema (portal)
    |   portal_admin/      → respaldos creados por este sistema (admin)
    |   pet/               → respaldos creados por este sistema (roke.pet)
    |   clients/           → respaldos creados por este sistema (clientes)
    |   hosting/           → respaldos creados por este sistema (hosting)
    |   game_server/       → respaldos creados por este sistema (game servers)
    |   dell/db/           → volcados SQL pre-existentes (cron externo)
    |   dell/configs/      → configs del sistema pre-existentes (cron externo)
    */
    'scan_dirs' => [
        // Directorios propios del sistema
        'platform', 'landing', 'portal_client', 'portal_admin', 'pet',
        'clients', 'hosting', 'game_server',
        // Backups pre-existentes en el NAS (scripts externos)
        'dell/db', 'dell/configs',
    ],
];
