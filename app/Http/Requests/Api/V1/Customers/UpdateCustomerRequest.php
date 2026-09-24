<?php

namespace App\Http\Requests\Api\V1\Customers;

use App\Enums\LeadSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('customer'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'source' => ['sometimes', 'nullable', Rule::enum(LeadSource::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['email', 'phone', 'source'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->exists('email') && ! $this->exists('phone')) {
                return;
            }

            $email = $this->exists('email') ? $this->input('email') : $this->route('customer')->email;
            $phone = $this->exists('phone') ? $this->input('phone') : $this->route('customer')->phone;

            if (blank($email) && blank($phone)) {
                $validator->errors()->add('email', 'A customer must have an email or phone number.');
            }
        });
    }
}
