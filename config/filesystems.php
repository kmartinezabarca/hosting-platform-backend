<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application. Just store away!
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Here you may configure as many filesystem "disks" as you wish, and you
    | may even configure multiple disks of the same driver. Defaults have
    | been set up for each driver as an example of the required values.
    |
    | Supported Drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
            'throw' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
        ],

        /*
        | Disco del NAS (Mac mini) donde viven los respaldos.
        |
        | Como la conexión aún no está definida, el enfoque recomendado y
        | más portable es montar el share del Mac mini en el servidor
        | (SMB/NFS/AFP) y apuntar NAS_ROOT a esa ruta — así el driver
        | "local" lee/escribe directamente sobre el NAS sin dependencias.
        |
        | Si en el futuro se prefiere SFTP, basta instalar
        | league/flysystem-sftp-v3 y cambiar NAS_DRIVER=sftp con las
        | variables NAS_SFTP_*.
        */
        'nas' => env('NAS_DRIVER', 'local') === 'sftp'
            ? [
                'driver'   => 'sftp',
                'host'     => env('NAS_SFTP_HOST'),
                'username' => env('NAS_SFTP_USERNAME'),
                'password' => env('NAS_SFTP_PASSWORD'),
                'privateKey' => env('NAS_SFTP_PRIVATE_KEY'),
                'port'     => (int) env('NAS_SFTP_PORT', 22),
                'root'     => env('NAS_ROOT', '/backups'),
                'timeout'  => 30,
                'throw'    => true,
            ]
            : [
                'driver'     => 'local',
                'root'       => env('NAS_ROOT', storage_path('app/nas-backups')),
                'visibility' => 'private',
                'throw'      => true,
            ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
