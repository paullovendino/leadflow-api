<?php

namespace App\Http\Requests\Api\V1\Leads;

use App\Enums\LeadSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('lead'));
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
            'service_id' => ['sometimes', 'nullable', 'integer', 'exists:services,id'],
            'source' => ['sometimes', 'nullable', Rule::enum(LeadSource::class)],
            'message' => ['sometimes', 'nullable', 'string'],
            'assigned_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'pipeline_stage_id' => ['sometimes', 'required', 'integer', 'exists:pipeline_stages,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['email', 'phone', 'source', 'message', 'service_id', 'assigned_user_id'] as $field) {
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

            $email = $this->exists('email') ? $this->input('email') : $this->route('lead')->email;
            $phone = $this->exists('phone') ? $this->input('phone') : $this->route('lead')->phone;

            if (blank($email) && blank($phone)) {
                $validator->errors()->add('email', 'A lead must have an email or phone number.');
            }
        });
    }
}
