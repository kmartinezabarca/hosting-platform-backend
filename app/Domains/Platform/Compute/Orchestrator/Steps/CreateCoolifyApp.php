<?php

namespace App\Domains\Platform\Compute\Orchestrator\Steps;

use App\Domains\Platform\Compute\Enums\ResourceStatus;
use App\Domains\Platform\Compute\Models\Orchestration;
use App\Domains\Platform\Compute\Orchestrator\Step;
use App\Domains\Platform\Compute\Orchestrator\StepResult;
use App\Domains\Platform\Compute\Providers\Contracts\AppRuntimeDriver;
use App\Domains\Platform\Git\GitHubAppClient;

/**
 * Crea la aplicación en el runtime (idempotente: si el provider ref ya
 * existe, no re-crea). La URL del repo nace tokenizada si hay instalación
 * de GitHub — RefreshGitCredentials la renueva antes de cada build.
 */
class CreateCoolifyApp implements Step
{
    public function __construct(
        private readonly AppRuntimeDriver $driver,
        private readonly GitHubAppClient $github,
    ) {}

    public function execute(Orchestration $orchestration): StepResult
    {
        $resource = $orchestration->resource;

        if ($resource->providerRef('coolify')) {
            return StepResult::completed();
        }

        $environment = $resource->environment;
        $project = $environment->project;
        $stack = $project->detected_stack ?? [];

        $appId = $this->driver->createApplication($resource, [
            'git_url' => $this->gitUrl($project),
            'branch' => $environment->branch ?? $project->default_branch ?? 'main',
            'build_pack' => $this->buildPack($stack),
            'port' => data_get($stack, 'run.port', 8080),
            'environment_name' => $environment->slug,
            'health_check' => [
                'path' => data_get($stack, 'run.healthcheck', '/'),
            ],
        ]);

        $resource->providerRefs()->create([
            'provider' => 'coolify',
            'external_id' => $appId,
        ]);

        // El estado lo transiciona solo el orquestador (regla del blueprint).
        $resource->update(['status' => ResourceStatus::Provisioning]);

        return StepResult::completed();
    }

    private function gitUrl($project): string
    {
        if ($project->githubInstallation) {
            $token = $this->github->installationToken($project->githubInstallation->installation_id);

            return "https://x-access-token:{$token}@github.com/{$project->repo_full_name}.git";
        }

        return "https://github.com/{$project->repo_full_name}";
    }

    private function buildPack(array $stack): string
    {
        return match (data_get($stack, 'build.method')) {
            'dockerfile' => 'dockerfile',
            'static' => 'static',
            'compose' => 'dockercompose',
            default => 'nixpacks',
        };
    }
}
