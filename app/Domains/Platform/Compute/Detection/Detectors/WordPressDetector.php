<?php

namespace App\Domains\Platform\Compute\Detection\Detectors;

use App\Domains\Platform\Compute\Detection\RepoFiles;

/**
 * WordPress (mes 3). Distingue dos topologías reales porque el binding de
 * credenciales NO aplica igual en ambas:
 *
 *  - Bedrock / WordPress por Composer (roots/bedrock, roots/wordpress…): lee la
 *    config desde variables de entorno (vlucas/phpdotenv), así que SÍ emitimos
 *    binds de DB + salts + WP_ENV — el runtime los consume de verdad.
 *  - WordPress "clásico": wp-config.php fija las credenciales en código. Emitir
 *    binds de DB sería engañoso (no se leerían), así que NO se emiten; en su
 *    lugar se avisa que wp-config debe leer getenv para que el binding aplique.
 */
class WordPressDetector implements Detector
{
    /** Paquetes de Composer que delatan un WordPress gestionado por dependencias. */
    private const COMPOSER_PACKAGES = [
        'roots/bedrock',
        'roots/wordpress',
        'roots/wordpress-no-content',
        'johnpbloch/wordpress',
        'johnpbloch/wordpress-core',
    ];

    /** Archivos de la raíz que delatan un WordPress clásico. */
    private const CLASSIC_FILES = [
        'wp-config.php',
        'wp-config-sample.php',
        'wp-login.php',
        'wp-load.php',
        'wp-settings.php',
        'wp-blog-header.php',
    ];

    /** Constantes de seguridad que WordPress exige (Bedrock las toma del entorno). */
    private const SALT_KEYS = [
        'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
        'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT',
    ];

    public function detect(RepoFiles $repo): ?array
    {
        if ($this->isComposerWordPress($repo)) {
            return $this->bedrockResult($repo);
        }

        if ($this->isClassicWordPress($repo)) {
            return $this->classicResult($repo);
        }

        return null;
    }

    private function isComposerWordPress(RepoFiles $repo): bool
    {
        $composer = $repo->json('composer.json');
        $require  = array_keys(($composer['require'] ?? []) + ($composer['require-dev'] ?? []));

        return (bool) array_intersect($require, self::COMPOSER_PACKAGES)
            || $repo->exists('config/application.php'); // Bedrock
    }

    private function isClassicWordPress(RepoFiles $repo): bool
    {
        foreach (self::CLASSIC_FILES as $file) {
            if ($repo->exists($file)) {
                return true;
            }
        }

        // wp-content/ o wp-admin/ en la raíz (un checkout completo de WP).
        return (bool) array_intersect(['wp-content', 'wp-admin', 'wp-includes'], $repo->rootFiles());
    }

    private function bedrockResult(RepoFiles $repo): array
    {
        $composer = $repo->json('composer.json') ?? [];

        $salts = array_map(
            fn (string $key) => ['key' => $key, 'generate' => 'wp_salt'],
            self::SALT_KEYS,
        );

        return [
            'framework'       => 'wordpress',
            'confidence'      => 0.96,
            'language'        => 'php',
            'runtime_version' => $this->phpVersion($composer['require']['php'] ?? null),
            'kind'            => 'app',
            'build'           => [
                'method'   => 'nixpacks',
                'commands' => ['composer install --no-dev --optimize-autoloader'],
            ],
            // Bedrock sirve desde web/ (docroot); el resto desde la raíz.
            'run'             => ['root' => $repo->exists('web/index.php') ? 'web' : null, 'port' => 8080, 'healthcheck' => '/'],
            'needs'           => ['database' => 'mysql', 'redis' => false],
            'env_template'    => array_merge(
                [
                    ['key' => 'DB_NAME', 'bind' => 'database.name'],
                    ['key' => 'DB_USER', 'bind' => 'database.username'],
                    ['key' => 'DB_PASSWORD', 'bind' => 'database.password'],
                    ['key' => 'DB_HOST', 'bind' => 'database.host'],
                    ['key' => 'WP_ENV', 'value' => 'production'],
                ],
                $salts,
            ),
            'evidence'        => ['WordPress gestionado por Composer (Bedrock/roots) — config por variables de entorno'],
            'warnings'        => [
                'Define WP_HOME y WP_SITEURL con la URL asignada tras el primer deploy.',
            ],
        ];
    }

    private function classicResult(RepoFiles $repo): array
    {
        $matched = collect(self::CLASSIC_FILES)->first(fn ($f) => $repo->exists($f))
            ?? 'wp-content/';

        $hasComposer = $repo->json('composer.json') !== null;

        return [
            'framework'       => 'wordpress',
            'confidence'      => 0.97,
            'language'        => 'php',
            'runtime_version' => null,
            'kind'            => 'app',
            'build'           => [
                'method'   => 'nixpacks',
                'commands' => $hasComposer ? ['composer install --no-dev --optimize-autoloader'] : [],
            ],
            'run'             => ['port' => 8080, 'healthcheck' => '/'],
            'needs'           => ['database' => 'mysql', 'redis' => false],
            // Sin binds: wp-config.php clásico fija las credenciales en código y
            // no leería estas variables — emitirlas sería engañoso.
            'env_template'    => [],
            'evidence'        => ["{$matched} presente (WordPress clásico)"],
            'warnings'        => [
                'WordPress necesita una base de datos MySQL.',
                'wp-config.php fija las credenciales en código: el binding automático de la base de '
                . 'datos solo aplica si wp-config lee variables de entorno (getenv). Ajusta wp-config '
                . 'o pega las credenciales manualmente.',
            ],
        ];
    }

    /** "^8.2" / ">=8.1" → "8.2" (mejor esfuerzo). */
    private function phpVersion(?string $constraint): ?string
    {
        if ($constraint === null) {
            return null;
        }

        return preg_match('/(\d+\.\d+)/', $constraint, $m) ? $m[1] : null;
    }
}
