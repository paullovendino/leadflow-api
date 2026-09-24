<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $actor): bool
    {
        return true;
    }

    public function view(User $actor, Customer $customer): bool
    {
        if ($actor->isManager()) {
            return true;
        }

        return $customer->leads()->where('assigned_user_id', $actor->id)->exists();
    }

    public function create(User $actor): bool
    {
        return $actor->isManager();
    }

    public function update(User $actor, Customer $customer): bool
    {
        return $this->view($actor, $customer);
    }
}
