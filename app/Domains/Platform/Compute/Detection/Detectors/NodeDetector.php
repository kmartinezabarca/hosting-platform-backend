<?php

namespace App\Domains\Platform\Compute\Detection\Detectors;

use App\Domains\Platform\Compute\Detection\RepoFiles;

/**
 * Fallback genérico para Node.js: package.json con script start.
 * Confianza baja a propósito — cualquier detector más específico
 * (Next.js, etc.) debe ganarle.
 */
class NodeDetector implements Detector
{
    public function detect(RepoFiles $repo): ?array
    {
        $pkg = $repo->json('package.json');

        if ($pkg === null || ! isset($pkg['scripts']['start'])) {
            return null;
        }

        $hasBuild = isset($pkg['scripts']['build']);

        return [
            'framework'       => 'node',
            'confidence'      => 0.60,
            'language'        => 'javascript',
            'runtime_version' => isset($pkg['engines']['node']) && preg_match('/(\d+)/', $pkg['engines']['node'], $m)
                ? $m[1]
                : null,
            'kind'            => 'app',
            'build'           => [
                'method'   => 'nixpacks',
                'commands' => array_values(array_filter(['npm ci', $hasBuild ? 'npm run build' : null])),
            ],
            'run'             => ['command' => 'npm run start', 'port' => 3000, 'healthcheck' => '/'],
            'needs'           => ['database' => null, 'redis' => false],
            'env_template'    => [],
            'evidence'        => ['package.json con script start'],
            'warnings'        => ['Framework no identificado con precisión; usando preset genérico de Node.js.'],
        ];
    }
}
