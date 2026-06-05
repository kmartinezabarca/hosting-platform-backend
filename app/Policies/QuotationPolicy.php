<?php

namespace App\Policies;

use App\Domains\Platform\Models\Quotation;
use App\Models\User;

class QuotationPolicy
{
    // Admin middleware already enforces authentication.
    // This policy enforces business-rule authorization.

    public function viewAny(User $user): bool   { return true; }
    public function view(User $user, Quotation $q): bool { return true; }
    public function create(User $user): bool    { return true; }

    public function update(User $user, Quotation $q): bool
    {
        return $q->canBeModified();
    }

    public function delete(User $user, Quotation $q): bool
    {
        return $q->canBeDeleted();
    }

    public function send(User $user, Quotation $q): bool
    {
        return $q->canBeSent();
    }

    public function accept(User $user, Quotation $q): bool
    {
        return $q->canBeAccepted();
    }

    public function reject(User $user, Quotation $q): bool
    {
        return $q->canBeRejected();
    }

    public function reopen(User $user, Quotation $q): bool
    {
        return $q->canBeReopened();
    }

    public function regenerateLink(User $user, Quotation $q): bool
    {
        return $q->public_token !== null;
    }
}
