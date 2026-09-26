<?php

namespace App\Http\Requests\Api\V1\PublicApi;

use Illuminate\Foundation\Http\FormRequest;

class StorePublicLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'required_without:phone', 'email', 'max:255'],
            'phone' => ['nullable', 'required_without:email', 'string', 'max:50', 'regex:/^[0-9+()\\s.-]{7,50}$/'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'message' => ['nullable', 'string', 'max:2000'],
            'company' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid phone number.',
            'company.prohibited' => 'Unable to process this request.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $payload = [];

        foreach (['name', 'email', 'phone', 'message'] as $field) {
            $value = $this->input($field);
            $payload[$field] = is_string($value) ? trim($value) : $value;
        }

        foreach (['email', 'phone', 'message', 'service_id'] as $field) {
            if (($payload[$field] ?? $this->input($field)) === '') {
                $payload[$field] = null;
            }
        }

        if (! $this->filled('company')) {
            $this->request->remove('company');
        }

        $this->merge($payload);
    }
}
