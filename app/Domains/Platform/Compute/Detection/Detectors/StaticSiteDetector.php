<?php

namespace App\Domains\Platform\Compute\Detection\Detectors;

use App\Domains\Platform\Compute\Detection\RepoFiles;

/**
 * index.html en raíz sin manifiestos de build → sitio estático puro.
 */
class StaticSiteDetector implements Detector
{
    public function detect(RepoFiles $repo): ?array
    {
        if (! $repo->exists('index.html')) {
            return null;
        }

        if ($repo->exists('package.json') || $repo->exists('composer.json')) {
            return null; // hay build pipeline — que decida otro detector
        }

        return [
            'framework'    => 'static',
            'confidence'   => 0.50,
            'language'     => 'html',
            'kind'         => 'static_site',
            'build'        => ['method' => 'static', 'commands' => []],
            'run'          => ['static_dir' => '.'],
            'needs'        => ['database' => null, 'redis' => false],
            'env_template' => [],
            'evidence'     => ['index.html en raíz, sin package.json/composer.json'],
            'warnings'     => [],
        ];
    }
}
