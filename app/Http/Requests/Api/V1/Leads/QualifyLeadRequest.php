<?php

namespace App\Http\Requests\Api\V1\Leads;

use Illuminate\Foundation\Http\FormRequest;

class QualifyLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('qualify', $this->route('lead'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
