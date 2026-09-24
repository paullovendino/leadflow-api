<?php

namespace App\Http\Requests\Api\V1\Availability;

use App\Enums\DayOfWeek;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('availability'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'day_of_week' => ['sometimes', 'required', Rule::enum(DayOfWeek::class)],
            'start_time' => ['sometimes', 'required', 'date_format:H:i'],
            'end_time' => ['sometimes', 'required', 'date_format:H:i'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $availability = $this->route('availability');
                $start = $this->input('start_time', $availability?->start_time);
                $end = $this->input('end_time', $availability?->end_time);

                if (is_string($start)) {
                    $start = substr($start, 0, 5);
                }

                if (is_string($end)) {
                    $end = substr($end, 0, 5);
                }

                if (is_string($start) && is_string($end) && $start >= $end) {
                    $validator->errors()->add('end_time', 'The end time must be later than the start time.');
                }
            },
        ];
    }
}
