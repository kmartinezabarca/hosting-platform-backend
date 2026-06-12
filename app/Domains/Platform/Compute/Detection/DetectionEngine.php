<?php

namespace App\Domains\Platform\Compute\Detection;

use App\Domains\Platform\Compute\Detection\Detectors\ComposeDetector;
use App\Domains\Platform\Compute\Detection\Detectors\Detector;
use App\Domains\Platform\Compute\Detection\Detectors\LaravelDetector;
use App\Domains\Platform\Compute\Detection\Detectors\NextJsDetector;
use App\Domains\Platform\Compute\Detection\Detectors\NodeDetector;
use App\Domains\Platform\Compute\Detection\Detectors\StaticSiteDetector;

/**
 * Motor de detección de frameworks (blueprint doc 04 §2).
 *
 * Corre todos los detectores y gana la mayor confianza. Si además hay
 * Dockerfile, el método de build se sobreescribe a 'dockerfile' (lo
 * explícito gana a lo inferido) conservando los needs/env del framework.
 *
 * La salida se persiste en projects.detected_stack y es declarativa: el
 * orquestador convierte needs.database en un Resource hermano y enlaza
 * credenciales en env_vars (source=detection).
 */
class DetectionEngine
{
    /** @var class-string<Detector>[] */
    private const DETECTORS = [
        ComposeDetector::class,
        LaravelDetector::class,
        NextJsDetector::class,
        NodeDetector::class,
        StaticSiteDetector::class,
    ];

    public function detect(RepoFiles $repo): ?array
    {
        $results = [];

        foreach (self::DETECTORS as $class) {
            $result = (new $class())->detect($repo);
            if ($result !== null) {
                $results[] = $result;
            }
        }

        if ($results === []) {
            return $this->unknownResult($repo);
        }

        usort($results, fn ($a, $b) => $b['confidence'] <=> $a['confidence']);
        $best = $results[0];

        // Dockerfile explícito gana al método de build inferido (no aplica a
        // compose, que ya es topología explícita).
        if ($best['kind'] !== 'compose' && $repo->exists('Dockerfile')) {
            $best['build']      = ['method' => 'dockerfile', 'file' => 'Dockerfile'];
            $best['evidence'][] = 'Dockerfile presente — build explícito sobre inferido';
        }

        // Lockfiles duplicados = builds no deterministas; avisar.
        $locks = array_intersect(['package-lock.json', 'pnpm-lock.yaml', 'yarn.lock'], $repo->rootFiles());
        if (count($locks) > 1) {
            $best['warnings'][] = 'Múltiples lockfiles presentes: ' . implode(', ', $locks) . '.';
        }

        $best['detected_at'] = now()->toIso8601String();

        return $best;
    }

    private function unknownResult(RepoFiles $repo): ?array
    {
        if (! $repo->exists('Dockerfile')) {
            return null; // nada reconocible
        }

        return [
            'framework'    => 'docker',
            'confidence'   => 0.85,
            'language'     => null,
            'kind'         => 'app',
            'build'        => ['method' => 'dockerfile', 'file' => 'Dockerfile'],
            'run'          => [],
            'needs'        => ['database' => null, 'redis' => false],
            'env_template' => [],
            'evidence'     => ['Dockerfile presente sin framework reconocible'],
            'warnings'     => ['Define el puerto expuesto manualmente si no es 80/8080.'],
            'detected_at'  => now()->toIso8601String(),
        ];
    }
}
