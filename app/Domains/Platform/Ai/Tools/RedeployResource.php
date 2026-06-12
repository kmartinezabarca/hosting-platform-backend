<?php

namespace App\Domains\Platform\Ai\Tools;

use App\Domains\Platform\Compute\Enums\DeploymentStatus;
use App\Domains\Platform\Compute\Enums\DeploymentTrigger;
use App\Domains\Platform\Compute\Enums\ResourceStatus;
use App\Domains\Platform\Compute\Models\Resource;
use App\Domains\Platform\Compute\Orchestrator\Flows\DeployFlow;
use App\Domains\Platform\Compute\Orchestrator\OrchestrationService;
use App\Models\User;

/**
 * Vuelve a desplegar un recurso (re-build de la rama actual). Es seguro: si el
 * build falla, el contenedor en ejecución no se toca. Requiere confirmación.
 */
class RedeployResource implements WriteTool
{
    public function __construct(private readonly OrchestrationService $orchestrator)
    {
    }

    public function name(): string
    {
        return 'redeploy_resource';
    }

    public function description(): string
    {
        return 'Vuelve a desplegar un recurso (nuevo build de la rama). Requiere confirmación del usuario. '
            . 'Úsala para reintentar tras un fix de variables o cuando el usuario pida un redeploy/reinicio.';
    }

    public function tier(): ToolTier
    {
        return ToolTier::SafeWrite;
    }

    public function schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'resource' => ['type' => 'string', 'description' => 'UUID del recurso'],
                'branch'   => ['type' => 'string', 'description' => 'Rama a desplegar (default: la del ambiente)'],
            ],
            'required'   => ['resource'],
        ];
    }

    public function preview(User $user, array $arguments): array
    {
        $resource = $this->resolveDeployable($user, $arguments);
        if (is_string($resource)) {
            return ['ok' => false, 'error' => $resource];
        }

        $branch = $this->branchFor($resource, $arguments);

        return [
            'ok'      => true,
            'summary' => "Volver a desplegar «{$resource->name}» desde la rama {$branch}.",
        ];
    }

    public function execute(User $user, array $arguments): array
    {
        $resource = $this->resolveDeployable($user, $arguments);
        if (is_string($resource)) {
            return ['error' => $resource];
        }

        $deployment = $resource->deployments()->create([
            'trigger'              => DeploymentTrigger::Ai,
            'status'               => DeploymentStatus::Queued,
            'branch'               => $this->branchFor($resource, $arguments),
            'initiated_by_user_id' => $user->id,
            'initiated_by_ai'      => true,
        ]);

        $orchestration = $this->orchestrator->start(DeployFlow::key(), $resource, $deployment);

        return [
            'ok'            => true,
            'deployment'    => $deployment->uuid,
            'status'        => $deployment->status->value,
            'orchestration' => $orchestration->uuid,
        ];
    }

    /** @return Resource|string Recurso desplegable, o mensaje de error. */
    private function resolveDeployable(User $user, array $arguments): Resource|string
    {
        $resource = Resource::with('environment.project')
            ->where('uuid', $arguments['resource'] ?? '')
            ->first();

        if (! $resource || ! $user->can('view', $resource)) {
            return 'Recurso no encontrado o sin acceso.';
        }
        if (! $user->can('operate', $resource)) {
            return 'No tienes permisos para desplegar este recurso.';
        }
        if (! in_array($resource->status, [ResourceStatus::Running, ResourceStatus::Stopped, ResourceStatus::Degraded, ResourceStatus::Failed], true)) {
            return 'El recurso aún se está aprovisionando; no se puede desplegar todavía.';
        }

        return $resource;
    }

    private function branchFor(Resource $resource, array $arguments): string
    {
        return ($arguments['branch'] ?? null)
            ?: $resource->environment->branch
            ?: $resource->environment->project->default_branch;
    }
}
