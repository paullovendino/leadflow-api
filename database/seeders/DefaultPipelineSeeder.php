<?php

namespace Database\Seeders;

use App\Models\Pipeline;
use App\Models\PipelineStage;
use Illuminate\Database\Seeder;

class DefaultPipelineSeeder extends Seeder
{
    public function run(): void
    {
        $pipeline = Pipeline::query()->updateOrCreate(
            ['is_default' => true],
            [
                'name' => 'Default Lead Pipeline',
                'is_active' => true,
            ],
        );

        $stages = [
            ['name' => 'New', 'slug' => 'new', 'position' => 1],
            ['name' => 'Contacted', 'slug' => 'contacted', 'position' => 2],
            ['name' => 'Qualified', 'slug' => 'qualified', 'position' => 3],
            ['name' => 'Appointment Booked', 'slug' => 'appointment_booked', 'position' => 4],
            ['name' => 'Appointment Completed', 'slug' => 'appointment_completed', 'position' => 5],
            ['name' => 'Converted', 'slug' => 'converted', 'position' => 6],
            ['name' => 'Not Interested', 'slug' => 'not_interested', 'position' => 7],
            ['name' => 'Lost', 'slug' => 'lost', 'position' => 8],
            ['name' => 'No Response', 'slug' => 'no_response', 'position' => 9],
        ];

        foreach ($stages as $stage) {
            PipelineStage::query()->updateOrCreate(
                [
                    'pipeline_id' => $pipeline->id,
                    'slug' => $stage['slug'],
                ],
                $stage + [
                    'pipeline_id' => $pipeline->id,
                    'is_active' => true,
                ],
            );
        }
    }
}
