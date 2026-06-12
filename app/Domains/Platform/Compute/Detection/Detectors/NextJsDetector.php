<?php

namespace App\Domains\Platform\Compute\Detection\Detectors;

use App\Domains\Platform\Compute\Detection\RepoFiles;

class NextJsDetector implements Detector
{
    public function detect(RepoFiles $repo): ?array
    {
        $pkg = $repo->json('package.json');

        $hasNext = isset($pkg['dependencies']['next']) || isset($pkg['devDependencies']['next']);
        if (! $hasNext) {
            return null;
        }

        // output: 'export' en next.config.* → sitio estático, no servidor Node.
        $config   = $repo->content('next.config.js')
            ?? $repo->content('next.config.mjs')
            ?? $repo->content('next.config.ts');
        $isExport = $config !== null && preg_match('/output\s*:\s*[\'"]export[\'"]/', $config);

        return [
            'framework'       => 'nextjs',
            'confidence'      => 0.95,
            'language'        => 'javascript',
            'runtime_version' => $this->nodeVersion($pkg),
            'kind'            => $isExport ? 'static_site' : 'app',
            'build'           => [
                'method'   => 'nixpacks',
                'commands' => [$this->installCommand($repo), 'npm run build'],
            ],
            'run'             => $isExport
                ? ['static_dir' => 'out']
                : ['command' => 'npm run start', 'port' => 3000, 'healthcheck' => '/'],
            'needs'           => ['database' => null, 'redis' => false],
            'env_template'    => [],
            'evidence'        => array_values(array_filter([
                'package.json depende de next',
                $isExport ? 'next.config con output: export (estático)' : null,
            ])),
            'warnings'        => [],
        ];
    }

    private function nodeVersion(?array $pkg): ?string
    {
        $constraint = $pkg['engines']['node'] ?? null;

        return $constraint && preg_match('/(\d+)/', $constraint, $m) ? $m[1] : null;
    }

    private function installCommand(RepoFiles $repo): string
    {
        return match (true) {
            $repo->exists('pnpm-lock.yaml') => 'pnpm install --frozen-lockfile',
            $repo->exists('yarn.lock')      => 'yarn install --frozen-lockfile',
            default                         => 'npm ci',
        };
    }
}
