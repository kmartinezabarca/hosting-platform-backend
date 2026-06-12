<?php

namespace App\Domains\Platform\Compute\Orchestrator\Flows;

use App\Domains\Platform\Compute\Enums\DeploymentStatus;
use App\Domains\Platform\Compute\Models\Orchestration;
use App\Domains\Platform\Compute\Orchestrator\Flow;
use App\Domains\Platform\Compute\Orchestrator\Steps\ApplyDetectionBindings;
use App\Domains\Platform\Compute\Orchestrator\Steps\AwaitDeploymentFinished;
use App\Domains\Platform\Compute\Orchestrator\Steps\MarkResourceRunning;
use App\Domains\Platform\Compute\Orchestrator\Steps\RefreshGitCredentials;
use App\Domains\Platform\Compute\Orchestrator\Steps\SyncEnvVars;
use App\Domains\Platform\Compute\Orchestrator\Steps\TriggerBuild;

/**
 * Deploy sobre un recurso ya provisionado (push, manual, futuro rollback).
 * Si el build falla, el recurso conserva su estado anterior — el contenedor
 * que corre no se toca hasta que hay build exitoso.
 */
class DeployFlow extends Flow
{
    public static function key(): string
    {
        return 'deploy';
    }

    public function steps(): array
    {
        return [
            RefreshGitCredentials::class,
            ApplyDetectionBindings::class,
            SyncEnvVars::class,
            TriggerBuild::class,
            AwaitDeploymentFinished::class,
            MarkResourceRunning::class,
        ];
    }

    public function queue(): string
    {
        return config('compute.queues.deployments', 'deployments');
    }

    public function onFailure(Orchestration $orchestration, \Throwable $e): void
    {
        // AwaitDeploymentFinished ya marcó el deployment como failed con su
        // resumen; aquí solo cubrimos fallas de pasos previos al build.
        $deployment = $orchestration->deployment;

        if ($deployment && ! $deployment->status->isTerminal()) {
            $deployment->update([
                'status'        => DeploymentStatus::Failed,
                'finished_at'   => now(),
                'error_summary' => mb_substr($e->getMessage(), 0, 1000),
            ]);
        }
    }
}
