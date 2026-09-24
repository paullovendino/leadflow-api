<?php

namespace App\Http\Requests\Api\V1\Appointments;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;

class AvailableSlotsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Appointment::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'staff_user_id' => ['required', 'integer', 'exists:users,id'],
            'date' => ['required', 'date'],
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'ignore_appointment_id' => ['sometimes', 'nullable', 'integer', 'exists:appointments,id'],
        ];
    }
}
