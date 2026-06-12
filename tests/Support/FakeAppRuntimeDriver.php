<?php

namespace Tests\Support;

use App\Domains\Platform\Compute\Models\Project;
use App\Domains\Platform\Compute\Models\Resource;
use App\Domains\Platform\Compute\Providers\Contracts\AppRuntimeDriver;

/**
 * Driver fake del runtime para tests del orquestador: registra llamadas y
 * simula el ciclo de un build (N polls in_progress → finished|failed) con
 * logs acumulativos.
 */
class FakeAppRuntimeDriver implements AppRuntimeDriver
{
    /** @var array<int, array{method: string, args: array}> */
    public array $calls = [];

    public bool $failDeployment = false;

    public int $pollsUntilFinished = 2;

    private int $polls = 0;

    public function ensureProject(Project $project): string
    {
        $this->record(__FUNCTION__, func_get_args());

        return 'cool-proj-1';
    }

    public function createApplication(Resource $resource, array $config): string
    {
        $this->record(__FUNCTION__, [$resource->uuid, $config]);

        return 'cool-app-1';
    }

    public function updateGitRepository(string $appId, string $gitUrl, string $branch): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    public function syncEnvVars(string $appId, array $vars): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    public function setDomain(string $appId, string $fqdn): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    public function triggerDeploy(string $appId, ?string $commitSha = null): string
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->polls = 0;

        return 'cool-dep-1';
    }

    public function getDeployment(string $deploymentId): array
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->polls++;

        $logs = collect(range(1, $this->polls))
            ->map(fn ($i) => "build line {$i}")
            ->implode("\n");

        if ($this->polls < $this->pollsUntilFinished) {
            return ['status' => 'in_progress', 'logs' => $logs];
        }

        return [
            'status' => $this->failDeployment ? 'failed' : 'finished',
            'logs'   => $logs . "\n" . ($this->failDeployment ? 'ERROR: build failed' : 'build ok'),
        ];
    }

    public function startApplication(string $appId): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    public function stopApplication(string $appId): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    public function restartApplication(string $appId): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    public function deleteApplication(string $appId): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    public function called(string $method): bool
    {
        return collect($this->calls)->contains(fn ($c) => $c['method'] === $method);
    }

    private function record(string $method, array $args): void
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
    }
}
