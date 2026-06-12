<?php

namespace App\Domains\Platform\Compute\Detection\Detectors;

use App\Domains\Platform\Compute\Detection\RepoFiles;

class LaravelDetector implements Detector
{
    public function detect(RepoFiles $repo): ?array
    {
        $composer = $repo->json('composer.json');

        if (! isset($composer['require']['laravel/framework'])) {
            return null;
        }

        $hasArtisan  = $repo->exists('artisan');
        $phpVersion  = $this->phpVersion($composer['require']['php'] ?? null);
        $hasFrontend = $repo->exists('package.json');
        $requires    = array_keys($composer['require'] ?? []);

        $needsRedis = (bool) array_intersect($requires, ['predis/predis', 'ext-redis'])
            || isset($composer['require']['laravel/horizon']);

        $warnings = [];
        if (! $repo->exists('.env.example')) {
            $warnings[] = 'No se encontró .env.example — las variables requeridas se infieren.';
        }

        $build = array_values(array_filter([
            'composer install --no-dev --optimize-autoloader',
            $hasFrontend ? 'npm ci && npm run build' : null,
        ]));

        return [
            'framework'       => 'laravel',
            'confidence'      => $hasArtisan ? 0.98 : 0.90,
            'language'        => 'php',
            'runtime_version' => $phpVersion,
            'kind'            => 'app',
            'build'           => ['method' => 'nixpacks', 'commands' => $build],
            'run'             => [
                'release_command' => 'php artisan migrate --force',
                'port'            => 8080,
                'healthcheck'     => '/up',
            ],
            'needs'           => [
                'database'     => 'mysql',
                'redis'        => $needsRedis,
                'queue_worker' => true,
                'scheduler'    => true,
            ],
            'env_template'    => [
                ['key' => 'APP_KEY', 'generate' => 'laravel_key'],
                ['key' => 'APP_ENV', 'value' => 'production'],
                ['key' => 'DB_HOST', 'bind' => 'database.host'],
                ['key' => 'DB_DATABASE', 'bind' => 'database.name'],
                ['key' => 'DB_USERNAME', 'bind' => 'database.username'],
                ['key' => 'DB_PASSWORD', 'bind' => 'database.password'],
                ...($needsRedis ? [['key' => 'REDIS_HOST', 'bind' => 'redis.host']] : []),
            ],
            'evidence'        => array_values(array_filter([
                'composer.json requiere laravel/framework',
                $hasArtisan ? 'archivo artisan presente' : null,
            ])),
            'warnings'        => $warnings,
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
