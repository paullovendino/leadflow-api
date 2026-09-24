<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\AppointmentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof AppointmentStatus
            ? $this->status
            : AppointmentStatus::from((string) $this->status);

        return [
            'id' => $this->id,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'service' => new ServiceResource($this->whenLoaded('service')),
            'staff_user' => new UserResource($this->whenLoaded('staffUser')),
            'scheduled_date' => $this->scheduled_date?->toDateString(),
            'start_time' => substr((string) $this->start_time, 0, 5),
            'end_time' => substr((string) $this->end_time, 0, 5),
            'duration_minutes' => $this->whenLoaded('service', fn () => $this->service?->duration_minutes),
            'status' => $status->value,
            'status_label' => $status->label(),
            'allowed_transitions' => array_map(
                fn (AppointmentStatus $transition) => $transition->value,
                $status->allowedTransitions(),
            ),
            'notes' => $this->notes,
            'activities' => ActivityResource::collection($this->whenLoaded('activities')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
