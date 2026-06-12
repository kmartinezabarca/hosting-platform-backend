<?php

namespace App\Domains\Platform\Compute\Jobs;

use App\Domains\Platform\Compute\Detection\DetectionEngine;
use App\Domains\Platform\Compute\Detection\GithubRepoFiles;
use App\Domains\Platform\Compute\Models\Project;
use App\Domains\Platform\Git\GitHubAppClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Detección de framework en background — se dispara al crear un proyecto
 * con repo conectado. El endpoint POST /v2/projects/{p}/analyze hace lo
 * mismo en línea para re-detecciones bajo demanda.
 */
class AnalyzeProjectRepo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly int $projectId)
    {
    }

    public function handle(GitHubAppClient $github, DetectionEngine $engine): void
    {
        $project = Project::with('githubInstallation')->find($this->projectId);

        if (! $project || ! $project->repo_full_name || ! $project->githubInstallation) {
            return;
        }

        $stack = $engine->detect(new GithubRepoFiles(
            $github,
            $project->githubInstallation->installation_id,
            $project->repo_full_name,
            $project->default_branch,
        ));

        $project->update(['detected_stack' => $stack]);

        Log::info('Framework detectado', [
            'project'   => $project->slug,
            'framework' => $stack['framework'] ?? 'unknown',
        ]);
    }
}
