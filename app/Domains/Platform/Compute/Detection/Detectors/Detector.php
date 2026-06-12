<?php

namespace App\Domains\Platform\Compute\Detection\Detectors;

use App\Domains\Platform\Compute\Detection\RepoFiles;

interface Detector
{
    /**
     * Resultado parcial de detección o null si el framework no aplica.
     *
     * Forma esperada:
     * [
     *   'framework'    => 'laravel',
     *   'confidence'   => 0.98,          // 0–1
     *   'language'     => 'php',
     *   'kind'         => 'app',         // ResourceKind value
     *   'build'        => [...],
     *   'run'          => [...],
     *   'needs'        => [...],         // database, redis, queue_worker…
     *   'env_template' => [...],
     *   'evidence'     => ['composer.json requiere laravel/framework'],
     *   'warnings'     => [],
     * ]
     */
    public function detect(RepoFiles $repo): ?array;
}
