<?php

namespace App\Domains\Platform\Compute\Previews;

use App\Domains\Platform\Compute\Enums\DeploymentStatus;
use App\Domains\Platform\Compute\Enums\DeploymentTrigger;
use App\Domains\Platform\Compute\Enums\EnvironmentType;
use App\Domains\Platform\Compute\Enums\ResourceKind;
use App\Domains\Platform\Compute\Enums\ResourceStatus;
use App\Domains\Platform\Compute\Models\Environment;
use App\Domains\Platform\Compute\Models\Project;
use App\Domains\Platform\Compute\Models\Resource;
use App\Domains\Platform\Compute\Orchestrator\Flows\DeployFlow;
use App\Domains\Platform\Compute\Orchestrator\Flows\ProvisionAppFlow;
use App\Domains\Platform\Compute\Orchestrator\OrchestrationService;
use App\Domains\Platform\Compute\Providers\Contracts\AppRuntimeDriver;
use App\Domains\Platform\Git\GitHubAppClient;
use Illuminate\Support\Facades\Log;

/**
 * Ambientes preview por PR (mes 2 #1). Un PR abierto/actualizado crea (o
 * redespliega) un ambiente efímero `pr-{n}` con su propia app, y comenta la
 * URL en el PR; al cerrarse, lo destruye y actualiza el comentario.
 *
 * Aislamiento: el preview NO hereda env vars de producción (solo lo que el
 * detector inyecta — APP_KEY generada, etc.), así que un PR de un fork jamás
 * ve secretos. auto_deploy=false: los redeploys los dispara el evento de PR,
 * no el de push (evita desplegar dos veces el mismo commit).
 */
class PreviewEnvironmentService
{
    public function __construct(
        private readonly OrchestrationService $orchestrator,
        private readonly AppRuntimeDriver $driver,
        private readonly GitHubAppClient $github,
    ) {
    }

    /** Crea o redespliega el preview del PR y comenta/actualiza en GitHub. */
    public function sync(Project $project, int $prNumber, string $headBranch, ?string $headSha, ?string $title): void
    {
        if (! config('compute.previews.enabled', true)) {
            return;
        }

        // Plantilla: la primera app en un ambiente NO preview. Si el proyecto
        // aún no tiene una app desplegada, no hay nada que previsualizar.
        $template = $this->templateApp($project);
        if (! $template) {
            Log::debug('Preview omitido: el proyecto no tiene una app base', ['project' => $project->slug]);
            return;
        }

        $environment = $this->ensureEnvironment($project, $prNumber, $headBranch);

        $resource = $environment->resources()->first();
        $firstDeploy = $resource === null;

        if ($firstDeploy) {
            $resource = $this->createPreviewResource($project, $environment, $template, $prNumber);
        }

        $deployment = $resource->deployments()->create([
            'trigger'        => $firstDeploy ? DeploymentTrigger::PrOpen : DeploymentTrigger::PrSync,
            'status'         => DeploymentStatus::Queued,
            'branch'         => $headBranch,
            'commit_sha'     => $headSha,
            'commit_message' => $title !== null ? mb_substr($title, 0, 500) : null,
            'pr_number'      => $prNumber,
        ]);

        $this->orchestrator->start(
            $firstDeploy ? ProvisionAppFlow::key() : DeployFlow::key(),
            $resource,
            $deployment,
        );

        $this->comment($project, $environment, 'Desplegando…', $resource->spec['fqdn'] ?? null, $headSha);
    }

    /** Destruye el preview del PR (recursos + ambiente) y cierra el comentario. */
    public function teardown(Project $project, int $prNumber): void
    {
        $environment = $project->environments()
            ->where('slug', $this->slug($prNumber))
            ->where('ephemeral', true)
            ->first();

        if (! $environment) {
            return;
        }

        foreach ($environment->resources()->get() as $resource) {
            if ($ref = $resource->providerRef('coolify')) {
                try {
                    $this->driver->deleteApplication($ref->external_id);
                } catch (\Throwable $e) {
                    Log::warning('No se pudo borrar la app preview en el runtime', ['error' => $e->getMessage()]);
                }
            }
            $resource->delete();
        }

        $this->comment($project, $environment, 'Preview eliminado (PR cerrado).', null, null);

        // env_vars y resources caen por cascada / soft-delete; el ambiente se va.
        $environment->delete();
    }

    private function templateApp(Project $project): ?Resource
    {
        return Resource::whereHas(
            'environment',
            fn ($q) => $q->where('project_id', $project->id)
                ->where('type', '!=', EnvironmentType::Preview->value),
        )
            ->whereIn('kind', [ResourceKind::App->value, ResourceKind::StaticSite->value, ResourceKind::Compose->value])
            ->first();
    }

    private function ensureEnvironment(Project $project, int $prNumber, string $headBranch): Environment
    {
        $ttlDays = (int) config('compute.previews.ttl_days', 14);

        return $project->environments()->updateOrCreate(
            ['slug' => $this->slug($prNumber)],
            [
                'name'        => "PR #{$prNumber}",
                'type'        => EnvironmentType::Preview,
                'pr_number'   => $prNumber,
                'branch'      => $headBranch,
                'auto_deploy' => false,
                'ephemeral'   => true,
                'expires_at'  => now()->addDays($ttlDays),
            ],
        );
    }

    private function createPreviewResource(Project $project, Environment $environment, Resource $template, int $prNumber): Resource
    {
        // Hereda cpu/ram de la app base; nada de fqdn ni refs de proveedor.
        $spec = collect($template->spec)->only(['ram_mb', 'cpu'])->all();

        $resource = $environment->resources()->create([
            'kind'   => $template->kind,
            'name'   => "{$project->slug}-pr-{$prNumber}",
            'status' => ResourceStatus::Creating,
            'spec'   => $spec,
        ]);

        // fqdn determinista (mismo esquema que AttachDefaultDomain) para poder
        // publicar la URL en el comentario desde ya; el paso de dominio lo respeta.
        $fqdn = "{$project->slug}-{$environment->slug}-" . substr($resource->uuid, 0, 6)
            . '.' . config('compute.app_domain');
        $resource->update(['spec' => array_merge($spec, ['fqdn' => $fqdn])]);

        return $resource;
    }

    /** Crea o actualiza el comentario del PR (best-effort: no rompe el deploy). */
    private function comment(Project $project, Environment $environment, string $status, ?string $fqdn, ?string $sha): void
    {
        $installationId = $project->githubInstallation?->installation_id;
        if (! $installationId || ! $project->repo_full_name) {
            return;
        }

        $body = $this->commentBody($status, $fqdn, $sha);

        try {
            if ($environment->pr_comment_id) {
                $this->github->updateIssueComment($installationId, $project->repo_full_name, $environment->pr_comment_id, $body);
            } else {
                $commentId = $this->github->createIssueComment($installationId, $project->repo_full_name, $environment->pr_number, $body);
                $environment->update(['pr_comment_id' => $commentId]);
            }
        } catch (\Throwable $e) {
            Log::warning('No se pudo publicar el comentario del preview en GitHub', ['error' => $e->getMessage()]);
        }
    }

    private function commentBody(string $status, ?string $fqdn, ?string $sha): string
    {
        $url    = $fqdn ? "https://{$fqdn}" : '—';
        $commit = $sha ? '`' . substr($sha, 0, 7) . '`' : '—';

        return "### 🚀 Preview de ROKE Platform\n\n"
            . "| | |\n|---|---|\n"
            . "| **Estado** | {$status} |\n"
            . "| **URL** | {$url} |\n"
            . "| **Commit** | {$commit} |\n\n"
            . "_Este comentario se actualiza solo en cada cambio del PR._";
    }

    private function slug(int $prNumber): string
    {
        return "pr-{$prNumber}";
    }
}
