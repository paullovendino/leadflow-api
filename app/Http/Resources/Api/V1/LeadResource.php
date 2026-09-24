<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'source' => $this->source?->value,
            'message' => $this->message,
            'service' => $this->when(
                $this->relationLoaded('service'),
                fn () => $this->service === null ? null : new ServiceResource($this->service),
            ),
            'assigned_user' => $this->when(
                $this->relationLoaded('assignedUser'),
                fn () => $this->assignedUser === null ? null : new UserResource($this->assignedUser),
            ),
            'pipeline_stage' => new PipelineStageResource($this->whenLoaded('pipelineStage')),
            'customer' => $this->when(
                $this->relationLoaded('customer'),
                fn () => $this->customer === null ? null : new CustomerResource($this->customer),
            ),
            'notes' => NoteResource::collection($this->whenLoaded('notes')),
            'activities' => ActivityResource::collection($this->whenLoaded('activities')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
