<?php

namespace Database\Seeders;

use App\Enums\DayOfWeek;
use App\Enums\LeadSource;
use App\Enums\UserRole;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\Service;
use App\Models\StaffAvailability;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'administrator@leadflow.test'],
            [
                'name' => 'LeadFlow Administrator',
                'password' => 'password',
                'role' => UserRole::Administrator,
                'is_active' => true,
            ],
        );

        $manager = User::query()->updateOrCreate(
            ['email' => 'manager@leadflow.test'],
            [
                'name' => 'LeadFlow Manager',
                'password' => 'password',
                'role' => UserRole::Manager,
                'is_active' => true,
            ],
        );

        $staff = User::query()->updateOrCreate(
            ['email' => 'staff@leadflow.test'],
            [
                'name' => 'LeadFlow Staff',
                'password' => 'password',
                'role' => UserRole::Staff,
                'is_active' => true,
            ],
        );

        foreach ([
            [
                'name' => 'Initial Assessment',
                'description' => 'First visit to understand client needs.',
                'duration_minutes' => 60,
            ],
            [
                'name' => 'Physical Therapy Session',
                'description' => 'Standard treatment appointment.',
                'duration_minutes' => 45,
            ],
            [
                'name' => 'Follow-up Session',
                'description' => 'Shorter progress review.',
                'duration_minutes' => 30,
            ],
        ] as $service) {
            Service::query()->updateOrCreate(
                ['name' => $service['name']],
                $service + ['is_active' => true],
            );
        }

        StaffAvailability::query()->updateOrCreate(
            [
                'user_id' => $staff->id,
                'day_of_week' => DayOfWeek::Monday,
                'start_time' => '09:00:00',
                'end_time' => '12:00:00',
            ],
            ['is_active' => true],
        );

        StaffAvailability::query()->updateOrCreate(
            [
                'user_id' => $manager->id,
                'day_of_week' => DayOfWeek::Wednesday,
                'start_time' => '13:00:00',
                'end_time' => '17:00:00',
            ],
            ['is_active' => true],
        );

        $this->call(DefaultPipelineSeeder::class);

        $newStageId = PipelineStage::query()->where('slug', 'new')->value('id');
        $contactedStageId = PipelineStage::query()->where('slug', 'contacted')->value('id');
        $assessmentId = Service::query()->where('name', 'Initial Assessment')->value('id');

        Lead::query()->updateOrCreate(
            ['email' => 'jane.demo@leadflow.test'],
            [
                'name' => 'Jane Demo',
                'phone' => '09170000001',
                'service_id' => $assessmentId,
                'source' => LeadSource::Website,
                'message' => 'I would like to book an assessment.',
                'assigned_user_id' => null,
                'pipeline_stage_id' => $newStageId,
            ],
        );

        Lead::query()->updateOrCreate(
            ['email' => 'john.demo@leadflow.test'],
            [
                'name' => 'John Demo',
                'phone' => '09170000002',
                'service_id' => $assessmentId,
                'source' => LeadSource::Facebook,
                'message' => 'Following up from Facebook.',
                'assigned_user_id' => $staff->id,
                'pipeline_stage_id' => $contactedStageId,
            ],
        );
    }
}
