<?php

namespace App\Domains\Platform\Ai\Tools;

use App\Domains\Platform\Compute\Models\Resource;
use App\Models\User;

class ListDeployments implements Tool
{
    public function name(): string
    {
        return 'list_deployments';
    }

    public function description(): string
    {
        return 'Historial de deployments de un recurso: estado, rama, commit, duración y resumen de error si falló.';
    }

    public function schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'resource' => ['type' => 'string', 'description' => 'UUID del recurso'],
                'limit'    => ['type' => 'integer', 'description' => 'Máximo de deployments (default 5)'],
            ],
            'required'   => ['resource'],
        ];
    }

    public function execute(User $user, array $arguments): array
    {
        $resource = Resource::where('uuid', $arguments['resource'] ?? '')->first();

        if (! $resource || ! $user->can('view', $resource)) {
            return ['error' => 'Recurso no encontrado o sin acceso.'];
        }

        return [
            'deployments' => $resource->deployments()
                ->latest()
                ->limit(min(20, (int) ($arguments['limit'] ?? 5)))
                ->get()
                ->map(fn ($d) => [
                    'uuid'           => $d->uuid,
                    'status'         => $d->status,
                    'trigger'        => $d->trigger,
                    'branch'         => $d->branch,
                    'commit'         => $d->commit_sha,
                    'commit_message' => $d->commit_message,
                    'build_seconds'  => $d->build_seconds,
                    'error_summary'  => $d->error_summary,
                    'created_at'     => $d->created_at->toIso8601String(),
                ])->all(),
        ];
    }
}
