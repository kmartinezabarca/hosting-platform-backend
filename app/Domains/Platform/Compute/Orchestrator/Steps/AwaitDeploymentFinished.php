<?php

namespace App\Domains\Platform\Compute\Orchestrator\Steps;

use App\Domains\Platform\Compute\Enums\DeploymentStatus;
use App\Domains\Platform\Compute\Events\DeploymentLogChunk;
use App\Domains\Platform\Compute\Events\DeploymentStatusChanged;
use App\Domains\Platform\Compute\Models\Orchestration;
use App\Domains\Platform\Compute\Orchestrator\Step;
use App\Domains\Platform\Compute\Orchestrator\StepResult;
use App\Domains\Platform\Compute\Providers\Contracts\AppRuntimeDriver;
use RuntimeException;

/**
 * Polling del build: en cada pasada persiste el diff de logs como chunk
 * (deployment_logs) y lo re-emite por Reverb en private-deployment.{uuid}.
 * El offset de logs vive en el contexto de la saga, así que el streaming
 * sobrevive a restarts del worker.
 */
class AwaitDeploymentFinished implements Step
{
    public function __construct(private readonly AppRuntimeDriver $driver)
    {
    }

    public function execute(Orchestration $orchestration): StepResult
    {
        $deployment = $orchestration->deployment;
        $state      = $this->driver->getDeployment($deployment->provider_ref);

        $this->streamNewLogs($orchestration, $state['logs']);

        switch ($state['status']) {
            case 'queued':
                return StepResult::pending();

            case 'in_progress':
                if ($deployment->status !== DeploymentStatus::Building) {
                    $deployment->update(['status' => DeploymentStatus::Building]);
                    broadcast(new DeploymentStatusChanged($deployment->fresh()));
                }

                return StepResult::pending();

            case 'finished':
                $deployment->update([
                    'status'        => DeploymentStatus::Success,
                    'finished_at'   => now(),
                    'build_seconds' => $deployment->started_at?->diffInSeconds(now()),
                ]);
                broadcast(new DeploymentStatusChanged($deployment->fresh()));

                return StepResult::completed();

            default: // failed
                $deployment->update([
                    'status'      => DeploymentStatus::Failed,
                    'finished_at' => now(),
                    // Resumen provisional (cola del log); DiagnoseFailedDeployment
                    // lo reemplaza en background con la causa raíz legible.
                    'error_summary' => mb_substr($state['logs'], -1500) ?: 'Build falló sin logs.',
                ]);
                broadcast(new DeploymentStatusChanged($deployment->fresh()));

                \App\Domains\Platform\Ai\Jobs\DiagnoseFailedDeployment::dispatch($deployment->id);

                throw new RuntimeException("Deployment {$deployment->uuid} falló en el runtime.");
        }
    }

    private function streamNewLogs(Orchestration $orchestration, string $logs): void
    {
        $offset = (int) $orchestration->getContext('log_offset', 0);

        if (strlen($logs) <= $offset) {
            return;
        }

        $chunk      = substr($logs, $offset);
        $deployment = $orchestration->deployment;

        $seq = ((int) $deployment->logs()->where('stream', 'build')->max('seq')) + 1;

        $deployment->logs()->create([
            'seq'        => $seq,
            'stream'     => 'build',
            'chunk'      => $chunk,
            'created_at' => now(),
        ]);

        $orchestration->setContext('log_offset', strlen($logs));

        broadcast(new DeploymentLogChunk($deployment, $seq, 'build', $chunk));
    }
}
