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

    // Tipos de respaldo soportados.
    'types' => [
        'platform'     => 'Plataforma (BD + almacenamiento)',
        'game_server'  => 'Servidor de juego (Pterodactyl)',
        'hosting'      => 'Hosting (Coolify)',
        'client_files' => 'Archivos de cliente (NAS)',
    ],

    // Frecuencias permitidas para programaciones.
    'frequencies' => ['daily', 'weekly', 'monthly', 'cron'],

    // Ruta a mysqldump (para el respaldo de plataforma).
    'mysqldump_path' => env('BACKUP_MYSQLDUMP_PATH', 'mysqldump'),
];
