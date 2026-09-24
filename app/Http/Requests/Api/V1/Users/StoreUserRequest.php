<?php

namespace App\Http\Requests\Api\V1\Users;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::enum(UserRole::class), $this->allowedRole()],
            'is_active' => ['sometimes', 'boolean'],
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
