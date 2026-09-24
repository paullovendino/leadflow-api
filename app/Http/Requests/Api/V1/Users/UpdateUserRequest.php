<?php

namespace App\Http\Requests\Api\V1\Users;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->route('user');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['sometimes', 'required', 'string', 'min:8'],
            'role' => ['sometimes', 'required', Rule::enum(UserRole::class), $this->allowedRole()],
        ];
    }

    private function allowedRole(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if ($this->user()->isManager() && $value === UserRole::Administrator->value) {
                $fail('Managers cannot assign the administrator role.');
            }
        };
    }
}
