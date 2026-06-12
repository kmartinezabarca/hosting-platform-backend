<?php

namespace App\Policies;

use App\Domains\Platform\Compute\Enums\TeamRole;
use App\Domains\Platform\Compute\Models\Project;
use App\Domains\Platform\Compute\Models\Team;
use App\Models\User;

class ProjectPolicy
{
    public function view(User $user, Project $project): bool
    {
        return $project->team->hasMember($user);
    }

    /** create recibe el Team destino porque el proyecto aún no existe. */
    public function create(User $user, Team $team): bool
    {
        return $team->roleFor($user)?->atLeast(TeamRole::Developer) ?? false;
    }

    public function update(User $user, Project $project): bool
    {
        return $project->team->roleFor($user)?->atLeast(TeamRole::Developer) ?? false;
    }

    public function delete(User $user, Project $project): bool
    {
        return $project->team->roleFor($user)?->atLeast(TeamRole::Admin) ?? false;
    }
}
