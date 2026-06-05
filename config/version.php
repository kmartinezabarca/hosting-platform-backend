<?php

/*
|--------------------------------------------------------------------------
| Version del backend desplegado
|--------------------------------------------------------------------------
|
| Estos valores los escribe el pipeline de Jenkins (ver Jenkinsfile) en el
| entorno justo antes de `php artisan config:cache`, de modo que quedan
| horneados en la config cacheada y sobreviven en runtime con php-fpm.
|
| Fuera del deploy (dev local sin cache) caen a los valores por defecto.
| La fuente de verdad de la version es el tag git mas cercano (git describe).
|
*/

return [

    // SemVer del release: tag git, p.ej. "v1.5.0" (o "v1.5.0-3-gabc1234" entre releases).
    'number' => env('APP_VERSION', '0.0.0'),

    // SHA corto del commit desplegado.
    'commit' => env('APP_GIT_COMMIT', 'unknown'),

    // Numero de build de Jenkins.
    'build_id' => env('APP_BUILD_ID', 'unknown'),

    // Timestamp del build (RELEASE_TS): YYYYMMDD_HHMMSS.
    'built_at' => env('APP_BUILD_TIMESTAMP'),
];
