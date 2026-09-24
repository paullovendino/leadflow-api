<?php

namespace App\Policies;

use App\Models\StaffAvailability;
use App\Models\User;

class StaffAvailabilityPolicy
{
    public function viewAny(User $actor, User $owner): bool
    {
        return $actor->isManager() || $actor->is($owner);
    }

    public function view(User $actor, StaffAvailability $availability): bool
    {
        return $actor->isManager() || $actor->is($availability->user);
    }

    public function create(User $actor, User $owner): bool
    {
        return $actor->isManager() || $actor->is($owner);
    }

    public function update(User $actor, StaffAvailability $availability): bool
    {
        return $actor->isManager() || $actor->is($availability->user);
    }

    public function activate(User $actor, StaffAvailability $availability): bool
    {
        return $this->update($actor, $availability);
    }

    public function deactivate(User $actor, StaffAvailability $availability): bool
    {
        return $this->update($actor, $availability);
    }
}
