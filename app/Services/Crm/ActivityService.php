<?php

namespace App\Services\Crm;

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;

class ActivityService
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(Lead|Customer|Appointment $subject, ?User $actor, ActivityType $type, string $description, array $metadata = []): Activity
    {
        return $subject->activities()->create([
            'user_id' => $actor?->id,
            'type' => $type,
            'description' => $description,
            'metadata' => $metadata,
        ]);
    }
}
