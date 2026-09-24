<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

class LeadPolicy
{
    public function viewAny(User $actor): bool
    {
        return true;
    }

    public function view(User $actor, Lead $lead): bool
    {
        return $actor->isManager() || $lead->assigned_user_id === $actor->id;
    }

    public function create(User $actor): bool
    {
        return true;
    }

    public function update(User $actor, Lead $lead): bool
    {
        return $this->view($actor, $lead);
    }

    public function assign(User $actor, Lead $lead): bool
    {
        return $actor->isManager();
    }

    public function moveStage(User $actor, Lead $lead): bool
    {
        return $this->view($actor, $lead);
    }

    public function convert(User $actor, Lead $lead): bool
    {
        return $this->view($actor, $lead);
    }
}
