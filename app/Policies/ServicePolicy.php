<?php

namespace App\Policies;

use App\Models\Service;
use App\Models\User;

class ServicePolicy
{
    public function viewAny(User $actor): bool
    {
        return true;
    }

    public function view(User $actor, Service $service): bool
    {
        if ($actor->isManager() || $actor->isAdministrator()) {
            return true;
        }

        return $service->is_active;
    }

    public function create(User $actor): bool
    {
        return $actor->isManager();
    }

    public function update(User $actor, Service $service): bool
    {
        return $actor->isManager();
    }

    public function activate(User $actor, Service $service): bool
    {
        return $actor->isManager();
    }

    public function deactivate(User $actor, Service $service): bool
    {
        return $actor->isManager();
    }
}
