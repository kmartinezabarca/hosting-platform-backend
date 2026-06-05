<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Only admins and super_admins can manage users.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->isAdmin();
    }

    public function create(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function update(User $actor, User $target): bool
    {
        // Super admins can update anyone; admins cannot touch super admins
        if ($target->isSuperAdmin() && !$actor->isSuperAdmin()) {
            return false;
        }

        return $actor->isAdmin();
    }

    public function delete(User $actor, User $target): bool
    {
        // Cannot delete super admins; cannot self-delete
        if ($target->isSuperAdmin()) {
            return false;
        }

        if ($actor->id === $target->id) {
            return false;
        }

        return $actor->isAdmin();
    }

    public function updateStatus(User $actor, User $target): bool
    {
        return $this->update($actor, $target);
    }
}
