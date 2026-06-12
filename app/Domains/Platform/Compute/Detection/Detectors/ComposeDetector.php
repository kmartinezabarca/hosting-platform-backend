<?php

namespace App\Domains\Platform\Compute\Detection\Detectors;

use App\Domains\Platform\Compute\Detection\RepoFiles;

/**
 * docker-compose.yml → proyecto compose multi-servicio. Gana a cualquier
 * framework: si el repo declara su propia topología, se respeta.
 */
class ComposeDetector implements Detector
{
    private const FILES = ['docker-compose.yml', 'docker-compose.yaml', 'compose.yml', 'compose.yaml'];

    public function detect(RepoFiles $repo): ?array
    {
        $file = collect(self::FILES)->first(fn ($f) => $repo->exists($f));

        if ($file === null) {
            return null;
        }

        return [
            'framework'    => 'compose',
            'confidence'   => 0.99,
            'language'     => null,
            'kind'         => 'compose',
            'build'        => ['method' => 'compose', 'file' => $file],
            'run'          => [],
            'needs'        => ['database' => null, 'redis' => false],
            'env_template' => [],
            'evidence'     => ["{$file} presente"],
            'warnings'     => [],
        ];
    }
}
