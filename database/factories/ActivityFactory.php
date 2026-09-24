<?php

namespace Database\Factories;

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'activityable_type' => 'lead',
            'activityable_id' => Lead::factory(),
            'type' => ActivityType::LeadCreated,
            'description' => 'Lead created',
            'metadata' => [],
        ];
    }
}
