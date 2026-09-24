<?php

namespace Tests\Feature\Availability;

use App\Enums\DayOfWeek;
use App\Models\StaffAvailability;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_create_availability(): void
    {
        $manager = User::factory()->manager()->create();
        $staff = User::factory()->staff()->create();

        $this->actingAs($manager)
            ->postJson("/api/v1/users/{$staff->id}/availabilities", [
                'day_of_week' => DayOfWeek::Monday->value,
                'start_time' => '09:00',
                'end_time' => '12:00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.day_of_week', 'monday')
            ->assertJsonPath('data.start_time', '09:00')
            ->assertJsonPath('data.end_time', '12:00');
    }

    public function test_staff_can_manage_their_own_availability(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)
            ->postJson("/api/v1/users/{$staff->id}/availabilities", [
                'day_of_week' => DayOfWeek::Tuesday->value,
                'start_time' => '13:00',
                'end_time' => '17:00',
            ])
            ->assertCreated();
    }

    public function test_invalid_day_is_rejected(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)
            ->postJson("/api/v1/users/{$staff->id}/availabilities", [
                'day_of_week' => 'funday',
                'start_time' => '09:00',
                'end_time' => '12:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['day_of_week']);
    }

    public function test_end_time_before_start_time_is_rejected(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)
            ->postJson("/api/v1/users/{$staff->id}/availabilities", [
                'day_of_week' => DayOfWeek::Monday->value,
                'start_time' => '12:00',
                'end_time' => '09:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end_time']);
    }

    public function test_invalid_staff_reference_is_rejected(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)
            ->postJson('/api/v1/users/999999/availabilities', [
                'day_of_week' => DayOfWeek::Monday->value,
                'start_time' => '09:00',
                'end_time' => '12:00',
            ])
            ->assertNotFound();
    }

    public function test_availability_cannot_be_assigned_to_an_administrator(): void
    {
        $manager = User::factory()->manager()->create();
        $administrator = User::factory()->administrator()->create();

        $this->actingAs($manager)
            ->postJson("/api/v1/users/{$administrator->id}/availabilities", [
                'day_of_week' => DayOfWeek::Monday->value,
                'start_time' => '09:00',
                'end_time' => '12:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id']);
    }

    public function test_staff_cannot_manage_another_users_availability(): void
    {
        $staff = User::factory()->staff()->create();
        $other = User::factory()->staff()->create();

        $this->actingAs($staff)
            ->postJson("/api/v1/users/{$other->id}/availabilities", [
                'day_of_week' => DayOfWeek::Monday->value,
                'start_time' => '09:00',
                'end_time' => '12:00',
            ])
            ->assertForbidden();
    }

    public function test_conflicting_recurring_availability_is_rejected(): void
    {
        $staff = User::factory()->staff()->create();

        StaffAvailability::factory()->create([
            'user_id' => $staff->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
        ]);

        $this->actingAs($staff)
            ->postJson("/api/v1/users/{$staff->id}/availabilities", [
                'day_of_week' => DayOfWeek::Monday->value,
                'start_time' => '10:00',
                'end_time' => '13:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_time']);
    }

    public function test_adjacent_availability_is_allowed(): void
    {
        $staff = User::factory()->staff()->create();

        StaffAvailability::factory()->create([
            'user_id' => $staff->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
        ]);

        $this->actingAs($staff)
            ->postJson("/api/v1/users/{$staff->id}/availabilities", [
                'day_of_week' => DayOfWeek::Monday->value,
                'start_time' => '12:00',
                'end_time' => '17:00',
            ])
            ->assertCreated();
    }

    public function test_inactive_availability_is_excluded_from_active_working_hours(): void
    {
        $staff = User::factory()->staff()->create();

        StaffAvailability::factory()->inactive()->create([
            'user_id' => $staff->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
        ]);

        $this->actingAs($staff)
            ->getJson("/api/v1/users/{$staff->id}/availabilities?is_active=1")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($staff)
            ->postJson("/api/v1/users/{$staff->id}/availabilities", [
                'day_of_week' => DayOfWeek::Monday->value,
                'start_time' => '10:00',
                'end_time' => '13:00',
            ])
            ->assertCreated();
    }

    public function test_guests_cannot_manage_availability(): void
    {
        $staff = User::factory()->staff()->create();

        $this->postJson("/api/v1/users/{$staff->id}/availabilities", [
            'day_of_week' => DayOfWeek::Monday->value,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ])->assertUnauthorized();
    }
}
