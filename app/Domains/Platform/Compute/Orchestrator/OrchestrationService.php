<?php

namespace App\Domains\Platform\Compute\Orchestrator;

use App\Domains\Platform\Compute\Models\Deployment;
use App\Domains\Platform\Compute\Models\Orchestration;
use App\Domains\Platform\Compute\Models\Resource;

/**
 * Punto de entrada del orquestador: crea la fila de la saga con sus pasos
 * en pending y encola el runner. ÚNICA vía válida para arrancar flujos —
 * los controladores nunca tocan resources.status directamente.
 */
class OrchestrationService
{
    public function start(
        string $flowKey,
        ?Resource $resource = null,
        ?Deployment $deployment = null,
        array $context = [],
    ): Orchestration {
        $flow = FlowRegistry::resolve($flowKey);

        $orchestration = Orchestration::create([
            'resource_id'   => $resource?->id,
            'deployment_id' => $deployment?->id,
            'flow'          => $flowKey,
            'state'         => null,
            'steps'         => array_map(fn (string $stepClass) => [
                'step'        => $stepClass,
                'status'      => 'pending',
                'started_at'  => null,
                'finished_at' => null,
                'error'       => null,
            ], $flow->steps()),
            'context'       => $context,
        ]);

        RunOrchestration::dispatch($orchestration->id)->onQueue($flow->queue());

        return $orchestration;
    }
}
