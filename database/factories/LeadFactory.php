<?php

namespace Database\Factories;

use App\Enums\LeadSource;
use App\Models\Lead;
use App\Models\PipelineStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('09#########'),
            'source' => LeadSource::Website,
            'message' => fake()->optional()->sentence(),
            'pipeline_stage_id' => PipelineStage::factory(),
        ];
    }
}
