<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;

class AppointmentPolicy
{
    public function viewAny(User $actor): bool
    {
        return true;
    }

    public function view(User $actor, Appointment $appointment): bool
    {
        if ($actor->isManager()) {
            return true;
        }

        if ($appointment->staff_user_id === $actor->id) {
            return true;
        }

        return $actor->can('view', $appointment->customer);
    }

    public function create(User $actor): bool
    {
        return true;
    }

    public function update(User $actor, Appointment $appointment): bool
    {
        return $this->view($actor, $appointment);
    }

    public function changeStatus(User $actor, Appointment $appointment): bool
    {
        return $this->update($actor, $appointment);
    }
}
