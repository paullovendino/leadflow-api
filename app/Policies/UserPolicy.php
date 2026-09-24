<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isManager();
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->isManager() || $actor->is($user);
    }

    public function create(User $actor): bool
    {
        return $actor->isManager();
    }

    public function update(User $actor, User $user): bool
    {
        if ($actor->is($user)) {
            return false;
        }

        return $actor->isManager() && ! $user->isAdministrator();
    }

    public function activate(User $actor, User $user): bool
    {
        return $actor->isManager() && ! $user->isAdministrator();
    }

    public function deactivate(User $actor, User $user): bool
    {
        if ($actor->is($user)) {
            return false;
        }

        return $actor->isManager() && ! $user->isAdministrator();
    }
}
