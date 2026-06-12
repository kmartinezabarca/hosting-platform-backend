<?php

namespace App\Domains\Platform\Ai\Troubleshooting;

/**
 * Clasificación determinista de fallas de build/deploy por firmas en el log
 * (blueprint doc 03 §4). El pase regex corre SIEMPRE (gratis y auditable);
 * el LLM solo refina la explicación. Orden = prioridad: la primera firma que
 * matchea gana.
 */
class FailureClassifier
{
    /** @var array<string, array{patterns: string[], cause: string, fixes: string[]}> */
    private const TAXONOMY = [
        'missing_app_key' => [
            'patterns' => ['/No application encryption key has been specified/i'],
            'cause'    => 'Falta APP_KEY en las variables de entorno.',
            'fixes'    => ['Genera una APP_KEY y agrégala en las variables del ambiente.'],
        ],
        'missing_env_var' => [
            'patterns' => ['/Undefined environment variable/i', '/env(?:ironment)? variable .{1,60} (?:is )?(?:not set|missing|undefined)/i'],
            'cause'    => 'Falta una variable de entorno requerida por la aplicación.',
            'fixes'    => ['Revisa las variables del ambiente y agrega la que falta.'],
        ],
        'composer_dep_conflict' => [
            'patterns' => ['/Your requirements could not be resolved to an installable set of packages/i', '/composer\.json requires .{1,80} -> (?:satisfiable|found)/i'],
            'cause'    => 'Conflicto de dependencias de Composer.',
            'fixes'    => ['Verifica composer.json/composer.lock y la versión de PHP del runtime.'],
        ],
        'npm_dep_error' => [
            'patterns' => ['/npm ERR!/i', '/ERESOLVE unable to resolve dependency tree/i', '/pnpm ERR/i'],
            'cause'    => 'Error de dependencias de npm/pnpm durante la instalación.',
            'fixes'    => ['Reinstala el lockfile localmente y haz commit; revisa versiones incompatibles.'],
        ],
        'node_version_mismatch' => [
            'patterns' => ['/The engine "node" is incompatible/i', '/requires? Node(?:\.js)? (?:version )?[\d>=<.x ]+/i'],
            'cause'    => 'La versión de Node del runtime no coincide con la requerida.',
            'fixes'    => ['Define engines.node en package.json o ajusta la versión del runtime.'],
        ],
        'build_oom' => [
            'patterns' => ['/JavaScript heap out of memory/i', '/Killed.*(?:npm|node|composer)/i', '/Out of memory/i', '/exit code:? 137/i'],
            'cause'    => 'El build se quedó sin memoria.',
            'fixes'    => ['Aumenta la RAM del recurso o reduce el consumo del build.'],
        ],
        'migration_failed' => [
            'patterns' => ['/SQLSTATE\[/i', '/migration.{1,60}failed/i', '/php artisan migrate.{1,200}error/is'],
            'cause'    => 'Las migraciones de base de datos fallaron.',
            'fixes'    => ['Revisa la conexión a la base de datos (credenciales/host) y la migración que truena.'],
        ],
        'port_in_use' => [
            'patterns' => ['/address already in use/i', '/EADDRINUSE/i'],
            'cause'    => 'El puerto de la aplicación ya está ocupado.',
            'fixes'    => ['Verifica que la app escuche el puerto configurado en el spec.'],
        ],
        'dockerfile_error' => [
            'patterns' => ['/failed to solve/i', '/dockerfile parse error/i', '/error building image/i'],
            'cause'    => 'Error en el Dockerfile.',
            'fixes'    => ['Revisa la sintaxis del Dockerfile y que las rutas COPY existan.'],
        ],
        'healthcheck_timeout' => [
            'patterns' => ['/healthcheck.{1,60}(?:failed|timeout)/i', '/container .{1,60} unhealthy/i'],
            'cause'    => 'La aplicación no respondió el healthcheck a tiempo.',
            'fixes'    => ['Confirma el puerto y la ruta de healthcheck; revisa logs de runtime por crashes al arrancar.'],
        ],
        'disk_full' => [
            'patterns' => ['/no space left on device/i'],
            'cause'    => 'El nodo se quedó sin espacio en disco.',
            'fixes'    => ['Contacta soporte — es un problema de plataforma, no de tu aplicación.'],
        ],
    ];

    /**
     * @return array{taxon: string, cause: string, fixes: string[]}
     */
    public function classify(string $logs): array
    {
        foreach (self::TAXONOMY as $taxon => $def) {
            foreach ($def['patterns'] as $pattern) {
                if (preg_match($pattern, $logs)) {
                    return ['taxon' => $taxon, 'cause' => $def['cause'], 'fixes' => $def['fixes']];
                }
            }
        }

        return [
            'taxon' => 'unknown',
            'cause' => 'No se identificó una causa conocida en los logs.',
            'fixes' => ['Revisa el final del log de build para ver el error exacto.'],
        ];
    }

    /** Ventana de error: cola del log, donde casi siempre vive la causa. */
    public function errorWindow(string $logs, int $chars = 3000): string
    {
        return mb_substr($logs, -$chars);
    }
}
