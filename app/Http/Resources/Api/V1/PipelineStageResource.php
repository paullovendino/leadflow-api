<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PipelineStageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pipeline_id' => $this->pipeline_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'position' => $this->position,
            'is_active' => $this->is_active,
            'pipeline' => new PipelineResource($this->whenLoaded('pipeline')),
        ];
    }
}
