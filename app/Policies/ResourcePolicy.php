<?php

namespace App\Policies;

use App\Domains\Platform\Compute\Enums\TeamRole;
use App\Domains\Platform\Compute\Models\Resource;
use App\Models\User;

class ResourcePolicy
{
    public function view(User $user, Resource $resource): bool
    {
        return $resource->team()?->hasMember($user) ?? false;
    }

    /** Deploy, restart, scale, env vars. */
    public function operate(User $user, Resource $resource): bool
    {
        return $resource->team()?->roleFor($user)?->atLeast(TeamRole::Developer) ?? false;
    }

    public function delete(User $user, Resource $resource): bool
    {
        return $resource->team()?->roleFor($user)?->atLeast(TeamRole::Admin) ?? false;
    }
}
