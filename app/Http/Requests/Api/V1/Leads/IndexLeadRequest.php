<?php

namespace App\Http\Requests\Api\V1\Leads;

use App\Enums\LeadSource;
use App\Models\Lead;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Lead::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'stage' => ['sometimes', 'integer', 'exists:pipeline_stages,id'],
            'assigned_user' => ['sometimes', 'integer', 'exists:users,id'],
            'service' => ['sometimes', 'integer', 'exists:services,id'],
            'source' => ['sometimes', 'nullable', Rule::enum(LeadSource::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
