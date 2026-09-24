<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'description' => $this->description,
            'metadata' => $this->metadata ?? [],
            'user' => $this->when(
                $this->relationLoaded('user'),
                fn () => $this->user === null ? null : new UserResource($this->user),
            ),
            'created_at' => $this->created_at,
        ];
    }
}
