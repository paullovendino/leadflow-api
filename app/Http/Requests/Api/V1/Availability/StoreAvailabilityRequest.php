<?php

namespace App\Http\Requests\Api\V1\Availability;

use App\Enums\DayOfWeek;
use App\Models\StaffAvailability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [StaffAvailability::class, $this->route('user')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'day_of_week' => ['required', Rule::enum(DayOfWeek::class)],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $start = $this->input('start_time');
                $end = $this->input('end_time');

                if (is_string($start) && is_string($end) && $start >= $end) {
                    $validator->errors()->add('end_time', 'The end time must be later than the start time.');
                }
            },
        ];
    }
}
