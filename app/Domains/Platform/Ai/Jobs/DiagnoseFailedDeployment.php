<?php

namespace App\Domains\Platform\Ai\Jobs;

use App\Domains\Platform\Ai\Troubleshooting\DeploymentDiagnosis;
use App\Domains\Platform\Compute\Enums\DeploymentStatus;
use App\Domains\Platform\Compute\Models\Deployment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Modo automático del troubleshooting (blueprint doc 03 §4): cada deploy
 * fallido recibe diagnóstico en background y error_summary pasa de "cola
 * cruda del log" a causa raíz legible — la que viaja en la notificación
 * push y se muestra en el historial.
 */
class DiagnoseFailedDeployment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly int $deploymentId)
    {
        $this->onQueue('ai');
    }

    public function handle(DeploymentDiagnosis $diagnosis): void
    {
        $deployment = Deployment::find($this->deploymentId);

        if (! $deployment || $deployment->status !== DeploymentStatus::Failed) {
            return;
        }

        $result = $diagnosis->diagnose($deployment);

        $deployment->update([
            'error_summary' => mb_substr(
                $result['explanation'] . "\n\nFixes sugeridos:\n- " . implode("\n- ", $result['fixes']),
                0,
                2000
            ),
        ]);
    }
}
