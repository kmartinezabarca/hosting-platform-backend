<?php

namespace App\Domains\Platform\Compute\Orchestrator\Steps;

use App\Domains\Platform\Compute\Models\Orchestration;
use App\Domains\Platform\Compute\Orchestrator\Step;
use App\Domains\Platform\Compute\Orchestrator\StepResult;
use App\Domains\Platform\Compute\Providers\Contracts\AppRuntimeDriver;
use App\Domains\Platform\Git\GitHubAppClient;

/**
 * Los tokens de instalación de GitHub viven 1h, así que la URL tokenizada
 * del repo se renueva antes de CADA build. (Mejora futura: deploy keys por
 * proyecto para no persistir tokens en el runtime.)
 */
class RefreshGitCredentials implements Step
{
    public function __construct(
        private readonly AppRuntimeDriver $driver,
        private readonly GitHubAppClient $github,
    ) {
    }

    public function execute(Orchestration $orchestration): StepResult
    {
        $resource = $orchestration->resource;
        $project  = $resource->environment->project;

        if (! $project->githubInstallation) {
            return StepResult::completed(); // repo público — nada que renovar
        }

        $token = $this->github->installationToken($project->githubInstallation->installation_id);

        $this->driver->updateGitRepository(
            $resource->providerRef('coolify')->external_id,
            "https://x-access-token:{$token}@github.com/{$project->repo_full_name}.git",
            $resource->environment->branch ?? $project->default_branch ?? 'main',
        );

        return StepResult::completed();
    }
}
