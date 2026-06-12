<?php

namespace App\Domains\Platform\Ai\Tools;

use App\Domains\Platform\Compute\Enums\DeploymentStatus;
use App\Domains\Platform\Compute\Enums\DeploymentTrigger;
use App\Domains\Platform\Compute\Enums\ResourceStatus;
use App\Domains\Platform\Compute\Models\Deployment;
use App\Domains\Platform\Compute\Models\Resource;
use App\Domains\Platform\Compute\Orchestrator\Flows\DeployFlow;
use App\Domains\Platform\Compute\Orchestrator\OrchestrationService;
use App\Models\User;

/**
 * Revierte un recurso al código de un deployment exitoso anterior (re-despliega
 * su commit). El historial conserva ambos. Requiere confirmación del usuario.
 */
class RollbackDeployment implements WriteTool
{
    public function __construct(private readonly OrchestrationService $orchestrator)
    {
    }

    public function name(): string
    {
        return 'rollback_deployment';
    }

    public function description(): string
    {
        return 'Revierte un recurso al commit de un deployment exitoso anterior. Requiere confirmación. '
            . 'Úsala cuando un deploy reciente rompió algo y el usuario quiere volver a una versión estable.';
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
                'resource'   => ['type' => 'string', 'description' => 'UUID del recurso'],
                'deployment' => ['type' => 'string', 'description' => 'UUID del deployment exitoso al que revertir'],
            ],
            'required'   => ['resource', 'deployment'],
        ];
    }

    public function preview(User $user, array $arguments): array
    {
        $target = $this->resolveTarget($user, $arguments);
        if (is_string($target)) {
            return ['ok' => false, 'error' => $target];
        }

        $sha = $target->commit_sha ? substr($target->commit_sha, 0, 7) : 'sin commit';

        return [
            'ok'      => true,
            'summary' => "Revertir «{$target->resource->name}» al deployment {$sha} "
                . "del {$target->created_at->toDateString()}.",
        ];
    }

    public function execute(User $user, array $arguments): array
    {
        $target = $this->resolveTarget($user, $arguments);
        if (is_string($target)) {
            return ['error' => $target];
        }

        $resource = $target->resource;

        $rollback = $resource->deployments()->create([
            'trigger'              => DeploymentTrigger::Rollback,
            'status'               => DeploymentStatus::Queued,
            'branch'               => $target->branch,
            'commit_sha'           => $target->commit_sha,
            'commit_message'       => $target->commit_message,
            'initiated_by_user_id' => $user->id,
            'initiated_by_ai'      => true,
        ]);

        $orchestration = $this->orchestrator->start(DeployFlow::key(), $resource, $rollback);

        return [
            'ok'               => true,
            'deployment'       => $rollback->uuid,
            'rolled_back_from' => $target->uuid,
            'orchestration'    => $orchestration->uuid,
        ];
    }

    /** @return Deployment|string Deployment objetivo válido, o mensaje de error. */
    private function resolveTarget(User $user, array $arguments): Deployment|string
    {
        $resource = Resource::where('uuid', $arguments['resource'] ?? '')->first();
        if (! $resource || ! $user->can('view', $resource)) {
            return 'Recurso no encontrado o sin acceso.';
        }
        if (! $user->can('operate', $resource)) {
            return 'No tienes permisos para revertir este recurso.';
        }
        if (! in_array($resource->status, [ResourceStatus::Running, ResourceStatus::Stopped, ResourceStatus::Degraded, ResourceStatus::Failed], true)) {
            return 'El recurso aún se está aprovisionando; no se puede revertir todavía.';
        }

        $target = Deployment::where('uuid', $arguments['deployment'] ?? '')->first();
        if (! $target || $target->resource_id !== $resource->id) {
            return 'El deployment objetivo no pertenece a este recurso.';
        }
        if ($target->status !== DeploymentStatus::Success) {
            return 'Solo se puede revertir a un deployment exitoso.';
        }

        $target->setRelation('resource', $resource);

        return $target;
    }
}
