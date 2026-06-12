<?php

namespace App\Domains\Platform\Ai\Tools;

use App\Domains\Platform\Compute\Models\Resource;
use App\Models\User;

class GetResourceStatus implements Tool
{
    public function name(): string
    {
        return 'get_resource_status';
    }

    public function description(): string
    {
        return 'Estado detallado de un recurso (app o game server): status, url/dirección, spec, último deployment.';
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
                'resource' => ['type' => 'string', 'description' => 'UUID del recurso'],
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

        $latest = $resource->deployments()->latest()->first();

        return [
            'uuid'    => $resource->uuid,
            'kind'    => $resource->kind,
            'name'    => $resource->name,
            'status'  => $resource->status,
            'url'     => data_get($resource->spec, 'fqdn')
                ? 'https://' . $resource->spec['fqdn']
                : data_get($resource->spec, 'address'),
            'spec'    => collect($resource->spec)->except(['fqdn'])->all(),
            'health'  => $resource->health,
            'latest_deployment' => $latest ? [
                'uuid'          => $latest->uuid,
                'status'        => $latest->status,
                'branch'        => $latest->branch,
                'commit'        => $latest->commit_sha,
                'error_summary' => $latest->error_summary,
                'finished_at'   => $latest->finished_at?->toIso8601String(),
            ] : null,
        ];
    }
}
