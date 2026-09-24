<?php

namespace App\Http\Requests\Api\V1\Availability;

use App\Enums\DayOfWeek;
use App\Models\StaffAvailability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', [StaffAvailability::class, $this->route('user')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'day_of_week' => ['sometimes', Rule::enum(DayOfWeek::class)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
