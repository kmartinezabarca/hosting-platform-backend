<?php

namespace App\Domains\Platform\Compute\Orchestrator\Steps;

use App\Domains\Platform\Compute\Enums\DeploymentStatus;
use App\Domains\Platform\Compute\Enums\DeploymentTrigger;
use App\Domains\Platform\Compute\Events\DeploymentStatusChanged;
use App\Domains\Platform\Compute\Models\Orchestration;
use App\Domains\Platform\Compute\Orchestrator\Step;
use App\Domains\Platform\Compute\Orchestrator\StepResult;
use App\Domains\Platform\Compute\Providers\Contracts\AppRuntimeDriver;

/**
 * Dispara el build en el runtime. Si la saga no trae Deployment (provisión
 * inicial), lo crea aquí. Idempotente: si el deployment ya tiene
 * provider_ref, el build ya fue disparado.
 */
class TriggerBuild implements Step
{
    public function __construct(private readonly AppRuntimeDriver $driver)
    {
    }

    public function execute(Orchestration $orchestration): StepResult
    {
        $resource   = $orchestration->resource;
        $deployment = $orchestration->deployment;

        if ($deployment === null) {
            $deployment = $resource->deployments()->create([
                'trigger'              => DeploymentTrigger::Manual,
                'status'               => DeploymentStatus::Queued,
                'branch'               => $resource->environment->branch
                    ?? $resource->environment->project->default_branch,
                'initiated_by_user_id' => $orchestration->getContext('initiated_by_user_id'),
            ]);

            $orchestration->update(['deployment_id' => $deployment->id]);
        }

        if ($deployment->provider_ref) {
            return StepResult::completed();
        }

        $providerRef = $this->driver->triggerDeploy(
            $resource->providerRef('coolify')->external_id
        );

        $deployment->update([
            'provider_ref' => $providerRef,
            'status'       => DeploymentStatus::Building,
            'started_at'   => now(),
        ]);

        broadcast(new DeploymentStatusChanged($deployment->fresh()));

        return StepResult::completed();
    }
}
