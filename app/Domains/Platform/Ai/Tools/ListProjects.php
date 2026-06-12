<?php

namespace App\Domains\Platform\Ai\Tools;

use App\Domains\Platform\Compute\Models\Project;
use App\Models\User;

class ListProjects implements Tool
{
    public function name(): string
    {
        return 'list_projects';
    }

    public function description(): string
    {
        return 'Lista los proyectos del usuario con sus ambientes y recursos (apps, bases de datos, game servers) y el estado de cada uno.';
    }

    public function tier(): ToolTier
    {
        return ToolTier::Read;
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'required' => []];
    }

    public function execute(User $user, array $arguments): array
    {
        $projects = Project::forUser($user)
            ->whereNull('archived_at')
            ->with(['environments.resources'])
            ->limit(25)
            ->get();

        return [
            'projects' => $projects->map(fn (Project $p) => [
                'uuid'         => $p->uuid,
                'name'         => $p->name,
                'repo'         => $p->repo_full_name,
                'framework'    => data_get($p->detected_stack, 'framework'),
                'environments' => $p->environments->map(fn ($env) => [
                    'slug'      => $env->slug,
                    'resources' => $env->resources->map(fn ($r) => [
                        'uuid'   => $r->uuid,
                        'kind'   => $r->kind,
                        'name'   => $r->name,
                        'status' => $r->status,
                    ]),
                ]),
            ])->all(),
        ];
    }
}
