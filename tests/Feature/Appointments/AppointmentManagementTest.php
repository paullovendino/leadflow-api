<?php

namespace Tests\Feature\Appointments;

use App\Enums\ActivityType;
use App\Enums\AppointmentStatus;
use App\Enums\DayOfWeek;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Service;
use App\Models\StaffAvailability;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\SeedsDefaultPipeline;
use Tests\TestCase;

class AppointmentManagementTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDefaultPipeline;

    public function test_administrator_can_create_a_valid_appointment(): void
    {
        [$administrator, $staff, $customer, $service, $date] = $this->bookingContext(actorRole: 'administrator');

        $this->actingAs($administrator)
            ->postJson('/api/v1/appointments', $this->payload($customer, $service, $staff, $date, '10:00'))
            ->assertCreated()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.start_time', '10:00')
            ->assertJsonPath('data.end_time', '11:00')
            ->assertJsonPath('data.duration_minutes', 60)
            ->assertJsonPath('data.customer.id', $customer->id)
            ->assertJsonPath('data.staff_user.id', $staff->id);
    }

    public function test_manager_can_create_a_valid_appointment(): void
    {
        [$manager, $staff, $customer, $service, $date] = $this->bookingContext();

        $this->actingAs($manager)
            ->postJson('/api/v1/appointments', $this->payload($customer, $service, $staff, $date, '09:00'))
            ->assertCreated()
            ->assertJsonPath('data.end_time', '10:00');
    }

    public function test_invalid_customer_is_rejected(): void
    {
        [$manager, $staff, , $service, $date] = $this->bookingContext();

        $this->actingAs($manager)
            ->postJson('/api/v1/appointments', [
                'customer_id' => 99999,
                'service_id' => $service->id,
                'staff_user_id' => $staff->id,
                'scheduled_date' => $date,
                'start_time' => '10:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_id']);
    }

    public function test_inactive_service_cannot_be_booked(): void
    {
        [$manager, $staff, $customer, , $date] = $this->bookingContext();
        $service = Service::factory()->inactive()->create(['duration_minutes' => 60]);

        $this->actingAs($manager)
            ->postJson('/api/v1/appointments', $this->payload($customer, $service, $staff, $date, '10:00'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['service_id']);
    }

    public function test_inactive_staff_cannot_be_booked(): void
    {
        [$manager, , $customer, $service, $date] = $this->bookingContext();
        $staff = User::factory()->staff()->inactive()->create();
        $this->openAvailability($staff);

        $this->actingAs($manager)
            ->postJson('/api/v1/appointments', $this->payload($customer, $service, $staff, $date, '10:00'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['staff_user_id']);
    }

    public function test_administrator_cannot_be_booked_as_staff(): void
    {
        [$manager, , $customer, $service, $date] = $this->bookingContext();
        $administrator = User::factory()->administrator()->create();
        $this->openAvailability($administrator);

        $this->actingAs($manager)
            ->postJson('/api/v1/appointments', $this->payload($customer, $service, $administrator, $date, '10:00'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['staff_user_id']);
    }

    public function test_past_appointments_cannot_be_created(): void
    {
        [$manager, $staff, $customer, $service] = $this->bookingContext();
        $yesterday = now()->subDay()->toDateString();
        $weekday = DayOfWeek::from(strtolower(Carbon::parse($yesterday)->englishDayOfWeek));
        StaffAvailability::factory()->create([
            'user_id' => $staff->id,
            'day_of_week' => $weekday,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
        ]);

        $this->actingAs($manager)
            ->postJson('/api/v1/appointments', $this->payload($customer, $service, $staff, $yesterday, '10:00'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scheduled_date']);
    }

    public function test_appointment_inside_availability_is_accepted(): void
    {
        [$manager, $staff, $customer, $service, $date] = $this->bookingContext();

        $this->actingAs($manager)
            ->postJson('/api/v1/appointments', $this->payload($customer, $service, $staff, $date, '16:00'))
            ->assertCreated()
            ->assertJsonPath('data.end_time', '17:00');
    }

    public function test_appointment_outside_availability_is_rejected(): void
    {
        [$manager, $staff, $customer, $service, $date] = $this->bookingContext();

        $this->actingAs($manager)
            ->postJson('/api/v1/appointments', $this->payload($customer, $service, $staff, $date, '17:00'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_time']);
    }

    public function test_appointment_crossing_availability_boundary_is_rejected(): void
    {
        [$manager, $staff, $customer, $service, $date] = $this->bookingContext();

        $this->actingAs($manager)
            ->postJson('/api/v1/appointments', $this->payload($customer, $service, $staff, $date, '16:30'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_time']);
    }

    public function test_appointment_without_availability_is_rejected(): void
    {
        $manager = User::factory()->manager()->create();
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);
        $date = $this->nextMonday();

        $this->actingAs($manager)
            ->postJson('/api/v1/appointments', $this->payload($customer, $service, $staff, $date, '10:00'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_time']);
    }

    public function test_overlapping_appointment_is_rejected(): void
    {
        [$manager, $staff, $customer, $service, $date] = $this->bookingContext();
        $this->book($manager, $customer, $service, $staff, $date, '10:00');

        $this->actingAs($manager)
            ->postJson('/api/v1/appointments', $this->payload($customer, $service, $staff, $date, '10:30'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_time']);
    }

    public function test_adjacent_appointments_are_allowed(): void
    {
        [$manager, $staff, $customer, $service, $date] = $this->bookingContext();
        $this->book($manager, $customer, $service, $staff, $date, '10:00');

        $this->actingAs($manager)
            ->postJson('/api/v1/appointments', $this->payload($customer, $service, $staff, $date, '11:00'))
            ->assertCreated()
            ->assertJsonPath('data.start_time', '11:00')
            ->assertJsonPath('data.end_time', '12:00');
    }

    public function test_cancelled_appointment_does_not_block_the_same_slot(): void
    {
        [$manager, $staff, $customer, $service, $date] = $this->bookingContext();
        $appointment = $this->book($manager, $customer, $service, $staff, $date, '10:00');

        $this->actingAs($manager)
            ->patchJson("/api/v1/appointments/{$appointment->id}/status", [
                'status' => AppointmentStatus::Cancelled->value,
            ])
            ->assertOk();

        $this->actingAs($manager)
            ->postJson('/api/v1/appointments', $this->payload($customer, $service, $staff, $date, '10:00'))
            ->assertCreated();
    }

    public function test_appointment_can_be_rescheduled(): void
    {
        [$manager, $staff, $customer, $service, $date] = $this->bookingContext();
        $appointment = $this->book($manager, $customer, $service, $staff, $date, '10:00');

        $this->actingAs($manager)
            ->patchJson("/api/v1/appointments/{$appointment->id}", [
                'scheduled_date' => $date,
                'start_time' => '14:00',
            ])
            ->assertOk()
            ->assertJsonPath('data.start_time', '14:00')
            ->assertJsonPath('data.end_time', '15:00');
    }

    public function test_reschedule_into_unavailable_period_is_rejected(): void
    {
        [$manager, $staff, $customer, $service, $date] = $this->bookingContext();
        $appointment = $this->book($manager, $customer, $service, $staff, $date, '10:00');

        $this->actingAs($manager)
            ->patchJson("/api/v1/appointments/{$appointment->id}", [
                'start_time' => '18:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_time']);
    }

    public function test_reschedule_into_overlap_is_rejected(): void
    {
        [$manager, $staff, $customer, $service, $date] = $this->bookingContext();
        $this->book($manager, $customer, $service, $staff, $date, '10:00');
        $later = $this->book($manager, $customer, $service, $staff, $date, '14:00');

        $this->actingAs($manager)
            ->patchJson("/api/v1/appointments/{$later->id}", [
                'start_time' => '10:30',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_time']);
    }

    public function test_reschedule_into_the_past_is_rejected(): void
    {
        [$manager, $staff, $customer, $service, $date] = $this->bookingContext();
        $appointment = $this->book($manager, $customer, $service, $staff, $date, '10:00');

        $this->actingAs($manager)
            ->patchJson("/api/v1/appointments/{$appointment->id}", [
                'scheduled_date' => now()->subDay()->toDateString(),
                'start_time' => '10:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scheduled_date']);
    }

    public function test_valid_status_transitions(): void
    {
        [$manager, $staff, $customer, $service, $date] = $this->bookingContext();
        $appointment = $this->book($manager, $customer, $service, $staff, $date, '10:00');

        $this->actingAs($manager)
            ->patchJson("/api/v1/appointments/{$appointment->id}/status", [
                'status' => AppointmentStatus::Confirmed->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $this->actingAs($manager)
            ->patchJson("/api/v1/appointments/{$appointment->id}/status", [
                'status' => AppointmentStatus::Completed->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_scheduled_can_be_cancelled_or_completed(): void
    {
        [$manager, $staff, $customer, $service, $date] = $this->bookingContext();
        $cancelled = $this->book($manager, $customer, $service, $staff, $date, '09:00');
        $completed = $this->book($manager, $customer, $service, $staff, $date, '11:00');

        $this->actingAs($manager)
            ->patchJson("/api/v1/appointments/{$cancelled->id}/status", [
                'status' => AppointmentStatus::Cancelled->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->actingAs($manager)
            ->patchJson("/api/v1/appointments/{$completed->id}/status", [
                'status' => AppointmentStatus::Completed->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_confirmed_can_be_marked_no_show(): void
    {
        [$manager, $staff, $customer, $service, $date] = $this->bookingContext();
        $appointment = $this->book($manager, $customer, $service, $staff, $date, '10:00');

        $this->actingAs($manager)
            ->patchJson("/api/v1/appointments/{$appointment->id}/status", [
                'status' => AppointmentStatus::Confirmed->value,
            ])
            ->assertOk();

        $this->actingAs($manager)
            ->patchJson("/api/v1/appointments/{$appointment->id}/status", [
                'status' => AppointmentStatus::NoShow->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'no_show');
    }

    public function test_invalid_status_transitions_are_rejected(): void
    {
        [$manager, $staff, $customer, $service, $date] = $this->bookingContext();
        $appointment = $this->book($manager, $customer, $service, $staff, $date, '10:00');

        $this->actingAs($manager)
            ->patchJson("/api/v1/appointments/{$appointment->id}/status", [
                'status' => AppointmentStatus::NoShow->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->actingAs($manager)
            ->patchJson("/api/v1/appointments/{$appointment->id}/status", [
                'status' => AppointmentStatus::Cancelled->value,
            ])
            ->assertOk();

        $this->actingAs($manager)
            ->patchJson("/api/v1/appointments/{$appointment->id}/status", [
                'status' => AppointmentStatus::Confirmed->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_staff_can_create_and_view_appointments_for_assigned_customers(): void
    {
        $this->seedDefaultPipeline();
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        Lead::factory()->create([
            'assigned_user_id' => $staff->id,
            'customer_id' => $customer->id,
            'pipeline_stage_id' => $this->stageBySlug('converted')->id,
        ]);
        $service = Service::factory()->create(['duration_minutes' => 60]);
        $date = $this->nextMonday();
        $this->openAvailability($staff);

        $response = $this->actingAs($staff)
            ->postJson('/api/v1/appointments', $this->payload($customer, $service, $staff, $date, '10:00'))
            ->assertCreated();

        $id = $response->json('data.id');

        $this->actingAs($staff)
            ->getJson("/api/v1/appointments/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id);
    }

    public function test_staff_cannot_create_appointments_for_unrelated_customers(): void
    {
        $this->seedDefaultPipeline();
        $staff = User::factory()->staff()->create();
        $other = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        Lead::factory()->create([
            'assigned_user_id' => $other->id,
            'customer_id' => $customer->id,
            'pipeline_stage_id' => $this->stageBySlug('converted')->id,
        ]);
        $service = Service::factory()->create(['duration_minutes' => 60]);
        $date = $this->nextMonday();
        $this->openAvailability($staff);

        $this->actingAs($staff)
            ->postJson('/api/v1/appointments', $this->payload($customer, $service, $staff, $date, '10:00'))
            ->assertForbidden();
    }

    public function test_staff_cannot_view_unauthorized_appointments(): void
    {
        [$manager, $staff, $customer, $service, $date] = $this->bookingContext();
        $appointment = $this->book($manager, $customer, $service, $staff, $date, '10:00');
        $outsider = User::factory()->staff()->create();

        $this->actingAs($outsider)
            ->getJson("/api/v1/appointments/{$appointment->id}")
            ->assertForbidden();

        $this->actingAs($outsider)
            ->patchJson("/api/v1/appointments/{$appointment->id}/status", [
                'status' => AppointmentStatus::Confirmed->value,
            ])
            ->assertForbidden();
    }

    public function test_staff_list_is_scoped_to_authorized_appointments(): void
    {
        [$manager, $staff, $customer, $service, $date] = $this->bookingContext();
        $visible = $this->book($manager, $customer, $service, $staff, $date, '10:00');

        $otherStaff = User::factory()->staff()->create();
        $otherCustomer = Customer::factory()->create();
        $this->openAvailability($otherStaff);
        $hidden = $this->book($manager, $otherCustomer, $service, $otherStaff, $date, '10:00');

        $ids = collect(
            $this->actingAs($staff)
                ->getJson('/api/v1/appointments')
                ->assertOk()
                ->json('data'),
        )->pluck('id');

        $this->assertTrue($ids->contains($visible->id));
        $this->assertFalse($ids->contains($hidden->id));
    }

    public function test_appointments_can_be_filtered_and_paginated(): void
    {
        [$manager, $staff, $customer, $service, $monday] = $this->bookingContext();
        $otherStaff = User::factory()->staff()->create();
        $otherCustomer = Customer::factory()->create();
        $otherService = Service::factory()->create(['duration_minutes' => 30]);
        $this->openAvailability($otherStaff);
        $tuesday = Carbon::parse($monday)->addDay()->toDateString();
        StaffAvailability::factory()->create([
            'user_id' => $staff->id,
            'day_of_week' => DayOfWeek::Tuesday,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
        ]);

        $first = $this->book($manager, $customer, $service, $staff, $monday, '10:00');
        $second = $this->book($manager, $otherCustomer, $otherService, $otherStaff, $monday, '11:00');
        $third = $this->book($manager, $customer, $service, $staff, $tuesday, '10:00');

        $this->actingAs($manager)
            ->patchJson("/api/v1/appointments/{$second->id}/status", [
                'status' => AppointmentStatus::Confirmed->value,
            ])
            ->assertOk();

        $this->actingAs($manager)
            ->getJson('/api/v1/appointments?date='.$monday)
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($manager)
            ->getJson('/api/v1/appointments?date_from='.$tuesday.'&date_to='.$tuesday)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $third->id);

        $this->actingAs($manager)
            ->getJson('/api/v1/appointments?staff_user_id='.$staff->id)
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($manager)
            ->getJson('/api/v1/appointments?customer_id='.$otherCustomer->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $second->id);

        $this->actingAs($manager)
            ->getJson('/api/v1/appointments?service_id='.$otherService->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($manager)
            ->getJson('/api/v1/appointments?status=confirmed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $second->id);

        $this->actingAs($manager)
            ->getJson('/api/v1/appointments?search='.urlencode($customer->name))
            ->assertOk()
            ->assertJsonFragment(['id' => $first->id]);

        $this->actingAs($manager)
            ->getJson('/api/v1/appointments?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 3);
    }

    public function test_creating_an_appointment_records_activities(): void
    {
        [$manager, $staff, $customer, $service, $date] = $this->bookingContext();

        $id = $this->actingAs($manager)
            ->postJson('/api/v1/appointments', $this->payload($customer, $service, $staff, $date, '10:00'))
            ->assertCreated()
            ->json('data.id');

        $this->assertDatabaseHas('activities', [
            'activityable_id' => $id,
            'activityable_type' => 'appointment',
            'type' => ActivityType::AppointmentCreated->value,
        ]);

        $this->assertDatabaseHas('activities', [
            'activityable_id' => $customer->id,
            'activityable_type' => 'customer',
            'type' => ActivityType::AppointmentCreated->value,
        ]);
    }

    public function test_status_and_reschedule_record_activities(): void
    {
        [$manager, $staff, $customer, $service, $date] = $this->bookingContext();
        $appointment = $this->book($manager, $customer, $service, $staff, $date, '10:00');

        $this->actingAs($manager)
            ->patchJson("/api/v1/appointments/{$appointment->id}", [
                'start_time' => '13:00',
            ])
            ->assertOk();

        $this->actingAs($manager)
            ->patchJson("/api/v1/appointments/{$appointment->id}/status", [
                'status' => AppointmentStatus::Confirmed->value,
            ])
            ->assertOk();

        $this->assertDatabaseHas('activities', [
            'activityable_id' => $appointment->id,
            'activityable_type' => 'appointment',
            'type' => ActivityType::AppointmentUpdated->value,
        ]);

        $this->assertDatabaseHas('activities', [
            'activityable_id' => $appointment->id,
            'activityable_type' => 'appointment',
            'type' => ActivityType::AppointmentConfirmed->value,
        ]);
    }

    public function test_available_slots_exclude_overlaps_and_unavailable_times(): void
    {
        [$manager, $staff, $customer, $service, $date] = $this->bookingContext();
        $this->book($manager, $customer, $service, $staff, $date, '10:00');

        $slots = $this->actingAs($manager)
            ->getJson('/api/v1/appointments/slots?staff_user_id='.$staff->id.'&date='.$date.'&service_id='.$service->id)
            ->assertOk()
            ->json('data');

        $starts = collect($slots)->pluck('start_time')->all();

        $this->assertContains('09:00', $starts);
        $this->assertContains('11:00', $starts);
        $this->assertNotContains('10:00', $starts);
        $this->assertNotContains('10:30', $starts);
        $this->assertNotContains('16:30', $starts);
    }

    public function test_customer_detail_includes_appointments(): void
    {
        [$manager, $staff, $customer, $service, $date] = $this->bookingContext();
        $appointment = $this->book($manager, $customer, $service, $staff, $date, '10:00');

        $this->actingAs($manager)
            ->getJson("/api/v1/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.appointments.0.id', $appointment->id)
            ->assertJsonPath('data.appointments.0.service.id', $service->id);
    }

    public function test_backend_calculates_end_time_from_service_duration(): void
    {
        [$manager, $staff, $customer, , $date] = $this->bookingContext();
        $service = Service::factory()->create(['duration_minutes' => 90]);

        $this->actingAs($manager)
            ->postJson('/api/v1/appointments', $this->payload($customer, $service, $staff, $date, '09:00'))
            ->assertCreated()
            ->assertJsonPath('data.end_time', '10:30');
    }

    /**
     * @return array{0: User, 1: User, 2: Customer, 3: Service, 4: string}
     */
    private function bookingContext(string $actorRole = 'manager'): array
    {
        $actor = $actorRole === 'administrator'
            ? User::factory()->administrator()->create()
            : User::factory()->manager()->create();
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);
        $date = $this->nextMonday();
        $this->openAvailability($staff);

        return [$actor, $staff, $customer, $service, $date];
    }

    private function openAvailability(User $staff): void
    {
        StaffAvailability::factory()->create([
            'user_id' => $staff->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
        ]);
    }

    private function nextMonday(): string
    {
        return now()->next(Carbon::MONDAY)->toDateString();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Customer $customer, Service $service, User $staff, string $date, string $start): array
    {
        return [
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'staff_user_id' => $staff->id,
            'scheduled_date' => $date,
            'start_time' => $start,
            'notes' => 'Bring intake form',
        ];
    }

    private function book(User $actor, Customer $customer, Service $service, User $staff, string $date, string $start): Appointment
    {
        $id = $this->actingAs($actor)
            ->postJson('/api/v1/appointments', $this->payload($customer, $service, $staff, $date, $start))
            ->assertCreated()
            ->json('data.id');

        return Appointment::query()->findOrFail($id);
    }
}
