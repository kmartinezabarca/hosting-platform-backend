<?php

namespace App\Policies;

use App\Domains\Platform\Compute\Enums\TeamRole;
use App\Domains\Platform\Compute\Models\Team;
use App\Models\User;

class TeamPolicy
{
    public function view(User $user, Team $team): bool
    {
        return $team->hasMember($user);
    }

    public function update(User $user, Team $team): bool
    {
        return $team->roleFor($user)?->atLeast(TeamRole::Admin) ?? false;
    }

    /** Solo el owner elimina, y nunca el equipo personal. */
    public function delete(User $user, Team $team): bool
    {
        return ! $team->is_personal
            && (int) $team->owner_user_id === (int) $user->id;
    }

    public function manageMembers(User $user, Team $team): bool
    {
        return $team->roleFor($user)?->atLeast(TeamRole::Admin) ?? false;
    }
}
