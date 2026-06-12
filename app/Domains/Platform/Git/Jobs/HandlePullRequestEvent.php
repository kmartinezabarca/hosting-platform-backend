<?php

namespace App\Domains\Platform\Git\Jobs;

use App\Domains\Platform\Compute\Models\GithubInstallation;
use App\Domains\Platform\Compute\Models\Project;
use App\Domains\Platform\Compute\Previews\PreviewEnvironmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Evento de PR → mantiene el ambiente preview de cada proyecto del repo.
 * opened/reopened/synchronize crean o redespliegan; closed destruye. El resto
 * de acciones (labeled, assigned…) se ignoran.
 */
class HandlePullRequestEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $installationId,
        public readonly string $repoFullName,
        public readonly string $action,
        public readonly int $prNumber,
        public readonly string $headBranch,
        public readonly ?string $headSha,
        public readonly ?string $title,
    ) {
        $this->onQueue('deployments');
    }

    public function handle(PreviewEnvironmentService $previews): void
    {
        $isSync     = in_array($this->action, ['opened', 'reopened', 'synchronize'], true);
        $isTeardown = $this->action === 'closed';

        if (! $isSync && ! $isTeardown) {
            return;
        }

        $installation = GithubInstallation::where('installation_id', $this->installationId)->first();
        if (! $installation || $installation->suspended_at !== null) {
            return;
        }

        $projects = Project::where('github_installation_id', $installation->id)
            ->where('repo_full_name', $this->repoFullName)
            ->whereNull('archived_at')
            ->get();

        foreach ($projects as $project) {
            if ($isSync) {
                $previews->sync($project, $this->prNumber, $this->headBranch, $this->headSha, $this->title);
            } else {
                $previews->teardown($project, $this->prNumber);
            }
        }
    }
}
