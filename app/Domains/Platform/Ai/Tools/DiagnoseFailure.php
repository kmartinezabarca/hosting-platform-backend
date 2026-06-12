<?php

namespace App\Domains\Platform\Ai\Tools;

use App\Domains\Platform\Ai\Troubleshooting\DeploymentDiagnosis;
use App\Domains\Platform\Compute\Models\Deployment;
use App\Models\User;

class DiagnoseFailure implements Tool
{
    public function __construct(private readonly DeploymentDiagnosis $diagnosis)
    {
    }

    public function name(): string
    {
        return 'diagnose_failure';
    }

    public function description(): string
    {
        return 'Diagnostica un deployment fallido: clasifica la falla, explica la causa raíz y propone fixes. Úsala cuando el usuario pregunte por qué falló un deploy.';
    }

    public function tier(): ToolTier
    {
        return ToolTier::Read;
    }

    public function schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'deployment' => ['type' => 'string', 'description' => 'UUID del deployment fallido'],
            ],
            'required'   => ['deployment'],
        ];
    }

    public function execute(User $user, array $arguments): array
    {
        $deployment = Deployment::where('uuid', $arguments['deployment'] ?? '')->first();

        if (! $deployment || ! $user->can('view', $deployment->resource)) {
            return ['error' => 'Deployment no encontrado o sin acceso.'];
        }

        return $this->diagnosis->diagnose($deployment);
    }
}
