<?php

namespace App\Domains\Platform\Compute\Orchestrator\Flows;

use App\Domains\Platform\Compute\Enums\ResourceStatus;
use App\Domains\Platform\Compute\Models\Orchestration;
use App\Domains\Platform\Compute\Orchestrator\Flow;
use App\Domains\Platform\Compute\Orchestrator\Steps\AttachDefaultDomain;
use App\Domains\Platform\Compute\Orchestrator\Steps\AwaitDeploymentFinished;
use App\Domains\Platform\Compute\Orchestrator\Steps\CreateCoolifyApp;
use App\Domains\Platform\Compute\Orchestrator\Steps\MarkResourceRunning;
use App\Domains\Platform\Compute\Orchestrator\Steps\SyncEnvVars;
use App\Domains\Platform\Compute\Orchestrator\Steps\TriggerBuild;

/**
 * Provisión completa de una app: crear en Coolify → env vars → dominio
 * gratuito → primer build → running. Cero intervención de admin.
 */
class ProvisionAppFlow extends Flow
{
    public static function key(): string
    {
        return 'provision_app';
    }

    public function steps(): array
    {
        return [
            CreateCoolifyApp::class,
            SyncEnvVars::class,
            AttachDefaultDomain::class,
            TriggerBuild::class,
            AwaitDeploymentFinished::class,
            MarkResourceRunning::class,
        ];
    }

    public function onFailure(Orchestration $orchestration, \Throwable $e): void
    {
        $orchestration->resource?->update(['status' => ResourceStatus::Failed]);
    }
}
