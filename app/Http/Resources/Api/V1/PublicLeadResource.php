<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicLeadResource extends JsonResource
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
            'service' => $this->when(
                $this->relationLoaded('service'),
                fn () => $this->service === null
                    ? null
                    : [
                        'id' => $this->service->id,
                        'name' => $this->service->name,
                        'duration_minutes' => $this->service->duration_minutes,
                    ],
            ),
            'created_at' => $this->created_at,
        ];
    }
}
