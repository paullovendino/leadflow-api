<?php

namespace App\Services\Users;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class UserManagementService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return User::query()
            ->when(isset($filters['role']), fn ($query) => $query->where('role', $filters['role']))
            ->when(isset($filters['is_active']), fn ($query) => $query->where('is_active', $filters['is_active']))
            ->orderBy('name')
            ->paginate(15);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): User
    {
        return User::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $user, array $attributes): User
    {
        if (array_key_exists('role', $attributes)) {
            $this->guardLastAdministrator($user, UserRole::from($attributes['role']));
        }

        $user->update($attributes);

        return $user->refresh();
    }

    public function activate(User $user): User
    {
        $user->update(['is_active' => true]);

        return $user->refresh();
    }

    public function deactivate(User $user): User
    {
        $this->guardLastAdministrator($user);

        $user->update(['is_active' => false]);

        return $user->refresh();
    }

    private function guardLastAdministrator(User $user, ?UserRole $nextRole = null): void
    {
        $wouldLeaveAdminRole = $nextRole !== null && $nextRole !== UserRole::Administrator;
        $wouldDeactivate = $nextRole === null;

        if (! $user->isAdministrator() || (! $wouldLeaveAdminRole && ! $wouldDeactivate)) {
            return;
        }

        $otherActiveAdministrators = User::query()
            ->where('role', UserRole::Administrator)
            ->where('is_active', true)
            ->whereKeyNot($user->id)
            ->exists();

        if (! $otherActiveAdministrators) {
            throw ValidationException::withMessages([
                $wouldLeaveAdminRole ? 'role' : 'is_active' => [
                    $wouldLeaveAdminRole
                        ? 'The last active administrator cannot be assigned a different role.'
                        : 'The last active administrator cannot be deactivated.',
                ],
            ]);
        }
    }
}
