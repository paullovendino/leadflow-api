<?php

namespace App\Http\Requests\Api\V1\Leads;

use App\Enums\LeadSource;
use App\Models\Lead;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Lead::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'required_without:phone', 'email', 'max:255'],
            'phone' => ['nullable', 'required_without:email', 'string', 'max:50'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'source' => ['nullable', Rule::enum(LeadSource::class)],
            'message' => ['nullable', 'string'],
            'assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'pipeline_stage_id' => ['nullable', 'integer', 'exists:pipeline_stages,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['email', 'phone', 'source', 'message', 'service_id', 'assigned_user_id', 'pipeline_stage_id'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }
}
