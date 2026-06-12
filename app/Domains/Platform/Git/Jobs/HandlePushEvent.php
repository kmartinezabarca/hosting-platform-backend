<?php

namespace App\Domains\Platform\Git\Jobs;

use App\Domains\Platform\Compute\Enums\DeploymentStatus;
use App\Domains\Platform\Compute\Enums\DeploymentTrigger;
use App\Domains\Platform\Compute\Enums\ResourceKind;
use App\Domains\Platform\Compute\Models\GithubInstallation;
use App\Domains\Platform\Compute\Models\Project;
use App\Domains\Platform\Compute\Orchestrator\Flows\DeployFlow;
use App\Domains\Platform\Compute\Orchestrator\OrchestrationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Push a una rama trackeada → crea Deployment(queued) por cada recurso app
 * de los ambientes con auto_deploy que siguen esa rama y arranca el
 * DeployFlow del orquestador para cada uno.
 */
class HandlePushEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $installationId,
        public readonly string $repoFullName,
        public readonly string $branch,
        public readonly ?string $commitSha,
        public readonly ?string $commitMessage,
    ) {
        $this->onQueue('deployments');
    }

    public function handle(): void
    {
        $installation = GithubInstallation::where('installation_id', $this->installationId)->first();

        if (! $installation || $installation->suspended_at !== null) {
            return;
        }

        $projects = Project::where('github_installation_id', $installation->id)
            ->where('repo_full_name', $this->repoFullName)
            ->whereNull('archived_at')
            ->with('environments.resources')
            ->get();

        foreach ($projects as $project) {
            $environments = $project->environments->filter(
                fn ($env) => $env->auto_deploy
                    && ($env->branch ?? $project->default_branch) === $this->branch
            );

            foreach ($environments as $environment) {
                $apps = $environment->resources->whereIn('kind', [
                    ResourceKind::App,
                    ResourceKind::StaticSite,
                    ResourceKind::Compose,
                ]);

                foreach ($apps as $resource) {
                    $deployment = $resource->deployments()->create([
                        'trigger'        => DeploymentTrigger::Push,
                        'status'         => DeploymentStatus::Queued,
                        'commit_sha'     => $this->commitSha,
                        'commit_message' => $this->commitMessage !== null
                            ? mb_substr($this->commitMessage, 0, 500)
                            : null,
                        'branch'         => $this->branch,
                    ]);

                    app(OrchestrationService::class)->start(DeployFlow::key(), $resource, $deployment);

                    Log::info('Deployment encolado por push', [
                        'project'  => $project->slug,
                        'env'      => $environment->slug,
                        'resource' => $resource->uuid,
                        'sha'      => $this->commitSha,
                    ]);
                }
            }
        }
    }
}
