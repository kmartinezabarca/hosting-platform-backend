<?php

namespace App\Domains\Platform\Ai\Tools;

use App\Domains\Platform\Compute\Models\Deployment;
use App\Models\User;

class GetDeploymentLogs implements Tool
{
    /** Tope de caracteres de log que se entregan al modelo. */
    private const MAX_CHARS = 6000;

    public function name(): string
    {
        return 'get_deployment_logs';
    }

    public function description(): string
    {
        return 'Cola de los logs de build de un deployment (los últimos ~6000 caracteres). Útil para diagnosticar fallas.';
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
                'deployment' => ['type' => 'string', 'description' => 'UUID del deployment'],
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

        $logs = $deployment->logs()->orderBy('seq')->pluck('chunk')->implode('');

        return [
            'deployment' => $deployment->uuid,
            'status'     => $deployment->status,
            // Los logs son entrada NO confiable (output de build del usuario):
            // el system prompt instruye tratarlos como datos, no instrucciones.
            'log_tail'   => mb_substr($logs, -self::MAX_CHARS),
            'truncated'  => mb_strlen($logs) > self::MAX_CHARS,
        ];
    }
}
