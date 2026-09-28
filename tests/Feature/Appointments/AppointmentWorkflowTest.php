<?php

namespace Tests\Feature\Appointments;

use App\Enums\AppointmentStatus;
use App\Enums\DayOfWeek;
use App\Enums\LeadSource;
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

class AppointmentWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDefaultPipeline;

    public function test_manual_customer_can_be_booked_without_a_lead(): void
    {
        [$manager, $staff, $service, $date] = $this->bookingStaff();
        $customer = Customer::factory()->create([
            'name' => 'Walk-in Customer',
            'email' => 'walkin@example.com',
        ]);

        $this->assertSame(0, $customer->leads()->count());

        $this->actingAs($manager)
            ->postJson('/api/v1/appointments', $this->payload($customer, $service, $staff, $date, '10:00'))
            ->assertCreated()
            ->assertJsonPath('data.customer.id', $customer->id)
            ->assertJsonPath('data.service.id', $service->id)
            ->assertJsonPath('data.staff_user.id', $staff->id)
            ->assertJsonPath('data.scheduled_date', $date)
            ->assertJsonPath('data.start_time', '10:00')
            ->assertJsonPath('data.status', AppointmentStatus::Scheduled->value);

        $this->assertSame(1, Appointment::query()->where('customer_id', $customer->id)->count());
    }

    public function test_qualified_lead_customer_can_be_booked(): void
    {
        $this->seedDefaultPipeline();
        [$manager, $staff, $service, $date] = $this->bookingStaff();
        $lead = Lead::factory()->create([
            'name' => 'Qualified Booker',
            'email' => 'qualified.booker@example.com',
            'phone' => '09175551212',
            'source' => LeadSource::Website,
            'pipeline_stage_id' => $this->stageBySlug('contacted')->id,
        ]);

        $qualify = $this->actingAs($manager)
            ->postJson("/api/v1/leads/{$lead->id}/qualify")
            ->assertOk();

        $customerId = $qualify->json('data.customer.id');

        $this->assertNotNull($customerId);
        $this->assertSame(0, Appointment::query()->where('customer_id', $customerId)->count());

        $this->actingAs($manager)
            ->postJson('/api/v1/appointments', [
                'customer_id' => $customerId,
                'service_id' => $service->id,
                'staff_user_id' => $staff->id,
                'scheduled_date' => $date,
                'start_time' => '10:00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.customer.id', $customerId)
            ->assertJsonPath('data.service.id', $service->id);

        $this->assertSame(1, Appointment::query()->where('customer_id', $customerId)->count());
        $this->assertSame($this->stageBySlug('qualified')->id, $lead->refresh()->pipeline_stage_id);
    }

    public function test_qualification_does_not_create_an_appointment(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        $lead = Lead::factory()->create([
            'pipeline_stage_id' => $this->stageBySlug('contacted')->id,
        ]);

        $this->actingAs($manager)
            ->postJson("/api/v1/leads/{$lead->id}/qualify")
            ->assertOk();

        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_conflicting_slot_is_rejected_with_a_clear_message(): void
    {
        [$manager, $staff, $service, $date] = $this->bookingStaff();
        $customer = Customer::factory()->create();

        $this->actingAs($manager)
            ->postJson('/api/v1/appointments', $this->payload($customer, $service, $staff, $date, '10:00'))
            ->assertCreated();

        $this->actingAs($manager)
            ->postJson('/api/v1/appointments', $this->payload($customer, $service, $staff, $date, '10:00'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_time'])
            ->assertJsonPath(
                'errors.start_time.0',
                'This time slot is no longer available. Please select another time.',
            );

        $this->assertSame(1, Appointment::query()->count());
    }

    public function test_missing_customer_returns_a_clear_validation_error(): void
    {
        [$manager, $staff, $service, $date] = $this->bookingStaff();

        $this->actingAs($manager)
            ->postJson('/api/v1/appointments', [
                'customer_id' => 99999,
                'service_id' => $service->id,
                'staff_user_id' => $staff->id,
                'scheduled_date' => $date,
                'start_time' => '10:00',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.customer_id.0', 'The selected customer could not be found.');
    }

    public function test_inactive_service_returns_a_clear_validation_error(): void
    {
        [$manager, $staff, , $date] = $this->bookingStaff();
        $customer = Customer::factory()->create();
        $service = Service::factory()->inactive()->create(['duration_minutes' => 60]);

        $this->actingAs($manager)
            ->postJson('/api/v1/appointments', $this->payload($customer, $service, $staff, $date, '10:00'))
            ->assertUnprocessable()
            ->assertJsonPath('errors.service_id.0', 'This service is no longer available.');
    }

    public function test_staff_cannot_book_an_unrelated_manual_customer(): void
    {
        [, $staff, $service, $date] = $this->bookingStaff();
        $outsider = User::factory()->staff()->create();
        $customer = Customer::factory()->create();

        $this->actingAs($outsider)
            ->postJson('/api/v1/appointments', $this->payload($customer, $service, $staff, $date, '10:00'))
            ->assertForbidden();

        $this->assertSame(0, Appointment::query()->count());
    }

    /**
     * @return array{0: User, 1: User, 2: Service, 3: string}
     */
    private function bookingStaff(): array
    {
        $manager = User::factory()->manager()->create();
        $staff = User::factory()->staff()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);
        $date = now()->next(Carbon::MONDAY)->toDateString();

        StaffAvailability::factory()->create([
            'user_id' => $staff->id,
            'day_of_week' => DayOfWeek::Monday,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
        ]);

        return [$manager, $staff, $service, $date];
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
        ];
    }
}
