<?php

namespace Tests\Feature\Dashboard;

use App\Enums\AppointmentStatus;
use App\Enums\LeadSource;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\SeedsDefaultPipeline;
use Tests\TestCase;

class DashboardMetricsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDefaultPipeline;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
    }

    public function test_empty_workspace_returns_zero_metrics(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.overview.total_leads', 0)
            ->assertJsonPath('data.overview.qualified_leads', 0)
            ->assertJsonPath('data.overview.converted_leads', 0)
            ->assertJsonPath('data.overview.total_customers', 0)
            ->assertJsonPath('data.appointments.today', 0)
            ->assertJsonPath('data.appointments.upcoming', 0)
            ->assertJsonPath('data.pipeline', [])
            ->assertJsonPath('data.lead_sources', [])
            ->assertJsonPath('data.recent_leads', [])
            ->assertJsonPath('data.recent_activity', []);
    }

    public function test_manager_receives_organization_wide_overview_and_pipeline(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        $staff = User::factory()->staff()->create();

        Lead::factory()->create([
            'pipeline_stage_id' => $this->stageBySlug('new')->id,
            'assigned_user_id' => $staff->id,
            'source' => LeadSource::Website,
        ]);
        Lead::factory()->count(2)->create([
            'pipeline_stage_id' => $this->stageBySlug('qualified')->id,
            'assigned_user_id' => $staff->id,
            'source' => LeadSource::Facebook,
        ]);
        Lead::factory()->create([
            'pipeline_stage_id' => $this->stageBySlug('converted')->id,
            'source' => LeadSource::Website,
        ]);
        Customer::factory()->count(3)->create();

        $response = $this->actingAs($manager)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.overview.total_leads', 4)
            ->assertJsonPath('data.overview.qualified_leads', 2)
            ->assertJsonPath('data.overview.converted_leads', 1)
            ->assertJsonPath('data.overview.total_customers', 3);

        $pipeline = $response->json('data.pipeline');
        $this->assertCount(9, $pipeline);
        $this->assertSame('new', $pipeline[0]['slug']);
        $this->assertSame(1, $pipeline[0]['count']);
        $this->assertSame('qualified', $pipeline[2]['slug']);
        $this->assertSame(2, $pipeline[2]['count']);
        $this->assertSame(
            ['new', 'contacted', 'qualified', 'appointment_booked', 'appointment_completed', 'converted', 'not_interested', 'lost', 'no_response'],
            array_column($pipeline, 'slug'),
        );

        $sources = collect($response->json('data.lead_sources'))->keyBy('source');
        $this->assertSame(2, $sources['website']['count']);
        $this->assertSame(2, $sources['facebook']['count']);
    }

    public function test_administrator_receives_organization_wide_metrics(): void
    {
        $this->seedDefaultPipeline();
        $administrator = User::factory()->administrator()->create();
        Lead::factory()->create(['pipeline_stage_id' => $this->stageBySlug('contacted')->id]);
        Customer::factory()->create();

        $this->actingAs($administrator)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.overview.total_leads', 1)
            ->assertJsonPath('data.overview.total_customers', 1);
    }

    public function test_staff_metrics_are_limited_to_assigned_and_related_records(): void
    {
        $this->seedDefaultPipeline();
        $staff = User::factory()->staff()->create();
        $other = User::factory()->staff()->create();

        Lead::factory()->create([
            'name' => 'Mine',
            'pipeline_stage_id' => $this->stageBySlug('qualified')->id,
            'assigned_user_id' => $staff->id,
            'source' => LeadSource::Website,
        ]);
        Lead::factory()->create([
            'name' => 'Theirs',
            'pipeline_stage_id' => $this->stageBySlug('qualified')->id,
            'assigned_user_id' => $other->id,
            'source' => LeadSource::Facebook,
        ]);

        $mineCustomer = Customer::factory()->create(['name' => 'Mine Customer']);
        $theirsCustomer = Customer::factory()->create(['name' => 'Theirs Customer']);
        Lead::factory()->create([
            'pipeline_stage_id' => $this->stageBySlug('converted')->id,
            'assigned_user_id' => $staff->id,
            'customer_id' => $mineCustomer->id,
        ]);
        Lead::factory()->create([
            'pipeline_stage_id' => $this->stageBySlug('converted')->id,
            'assigned_user_id' => $other->id,
            'customer_id' => $theirsCustomer->id,
        ]);

        $service = Service::factory()->create();
        Appointment::factory()->create([
            'customer_id' => $mineCustomer->id,
            'service_id' => $service->id,
            'staff_user_id' => $staff->id,
            'status' => AppointmentStatus::Scheduled,
            'scheduled_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
        ]);
        Appointment::factory()->create([
            'customer_id' => $theirsCustomer->id,
            'service_id' => $service->id,
            'staff_user_id' => $other->id,
            'status' => AppointmentStatus::Scheduled,
            'scheduled_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
        ]);

        $response = $this->actingAs($staff)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.overview.total_leads', 2)
            ->assertJsonPath('data.overview.qualified_leads', 1)
            ->assertJsonPath('data.overview.converted_leads', 1)
            ->assertJsonPath('data.overview.total_customers', 1)
            ->assertJsonPath('data.appointments.scheduled', 1)
            ->assertJsonPath('data.appointments.upcoming', 1);

        $names = collect($response->json('data.recent_leads'))->pluck('name');
        $this->assertTrue($names->contains('Mine'));
        $this->assertFalse($names->contains('Theirs'));

        $staffBreakdown = collect($response->json('data.appointment_breakdown.by_staff'))->pluck('staff_user_id');
        $this->assertTrue($staffBreakdown->contains($staff->id));
        $this->assertFalse($staffBreakdown->contains($other->id));

        $sources = collect($response->json('data.lead_sources'))->pluck('source');
        $this->assertTrue($sources->contains('website'));
        $this->assertFalse($sources->contains('facebook'));
    }

    public function test_qualified_count_is_current_stage_not_customer_link(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        $customer = Customer::factory()->create();

        Lead::factory()->create([
            'pipeline_stage_id' => $this->stageBySlug('qualified')->id,
            'customer_id' => $customer->id,
        ]);
        Lead::factory()->create([
            'pipeline_stage_id' => $this->stageBySlug('converted')->id,
            'customer_id' => $customer->id,
        ]);

        $this->actingAs($manager)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.overview.qualified_leads', 1)
            ->assertJsonPath('data.overview.converted_leads', 1);
    }

    public function test_pipeline_omits_inactive_stages_and_preserves_order(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        PipelineStage::query()->where('slug', 'lost')->update(['is_active' => false]);

        $response = $this->actingAs($manager)
            ->getJson('/api/v1/dashboard')
            ->assertOk();

        $slugs = array_column($response->json('data.pipeline'), 'slug');
        $this->assertNotContains('lost', $slugs);
        $this->assertSame('new', $slugs[0]);
        $this->assertSame('converted', $slugs[array_search('converted', $slugs, true)]);
    }

    public function test_null_lead_source_is_grouped(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        Lead::factory()->create([
            'pipeline_stage_id' => $this->stageBySlug('new')->id,
            'source' => null,
        ]);

        $this->actingAs($manager)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.lead_sources.0.source', null)
            ->assertJsonPath('data.lead_sources.0.count', 1);
    }

    public function test_appointment_today_upcoming_and_status_counts(): void
    {
        $this->seedDefaultPipeline();
        Carbon::setTestNow(Carbon::parse('2026-09-29 10:00:00', config('app.timezone')));

        $manager = User::factory()->manager()->create();
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        $service = Service::factory()->create();

        $this->makeAppointment($customer, $service, $staff, '2026-09-29', '09:00:00', AppointmentStatus::Scheduled);
        $this->makeAppointment($customer, $service, $staff, '2026-09-29', '11:00:00', AppointmentStatus::Confirmed);
        $this->makeAppointment($customer, $service, $staff, '2026-09-30', '10:00:00', AppointmentStatus::Scheduled);
        $this->makeAppointment($customer, $service, $staff, '2026-09-29', '08:00:00', AppointmentStatus::Completed);
        $this->makeAppointment($customer, $service, $staff, '2026-09-29', '12:00:00', AppointmentStatus::Cancelled);
        $this->makeAppointment($customer, $service, $staff, '2026-09-28', '10:00:00', AppointmentStatus::NoShow);

        $this->actingAs($manager)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.appointments.today', 4)
            ->assertJsonPath('data.appointments.upcoming', 2)
            ->assertJsonPath('data.appointments.scheduled', 2)
            ->assertJsonPath('data.appointments.confirmed', 1)
            ->assertJsonPath('data.appointments.completed', 1)
            ->assertJsonPath('data.appointments.cancelled', 1)
            ->assertJsonPath('data.appointments.no_show', 1);
    }

    public function test_today_uses_application_timezone_not_utc(): void
    {
        $this->assertSame('Asia/Manila', config('app.timezone'));
        $this->seedDefaultPipeline();
        Carbon::setTestNow(Carbon::parse('2026-09-29 00:30:00', 'Asia/Manila'));

        $manager = User::factory()->manager()->create();
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        $service = Service::factory()->create();

        $this->makeAppointment($customer, $service, $staff, '2026-09-29', '01:00:00', AppointmentStatus::Scheduled);
        $this->makeAppointment($customer, $service, $staff, '2026-09-28', '23:00:00', AppointmentStatus::Scheduled);

        $this->actingAs($manager)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.appointments.today', 1);
    }

    public function test_recent_leads_are_newest_first_and_limited_to_eight(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        $stage = $this->stageBySlug('new');

        foreach (range(1, 10) as $index) {
            Lead::factory()->create([
                'name' => 'Lead '.$index,
                'pipeline_stage_id' => $stage->id,
                'created_at' => now()->subMinutes(10 - $index),
            ]);
        }

        $names = $this->actingAs($manager)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->json('data.recent_leads');

        $this->assertCount(8, $names);
        $this->assertSame('Lead 10', $names[0]['name']);
        $this->assertSame('Lead 3', $names[7]['name']);
    }

    public function test_recent_activity_is_newest_first_and_staff_cannot_see_unrelated_entities(): void
    {
        $this->seedDefaultPipeline();
        $staff = User::factory()->staff()->create();
        $other = User::factory()->staff()->create();
        $stage = $this->stageBySlug('new');

        $mine = Lead::factory()->create([
            'name' => 'Visible Lead',
            'pipeline_stage_id' => $stage->id,
            'assigned_user_id' => $staff->id,
        ]);
        $theirs = Lead::factory()->create([
            'name' => 'Hidden Lead',
            'pipeline_stage_id' => $stage->id,
            'assigned_user_id' => $other->id,
        ]);

        $mine->activities()->create([
            'user_id' => $staff->id,
            'type' => 'lead_created',
            'description' => 'Mine created',
            'created_at' => now()->subMinute(),
        ]);
        $theirs->activities()->create([
            'user_id' => $other->id,
            'type' => 'lead_created',
            'description' => 'Theirs created',
            'created_at' => now(),
        ]);

        $feed = $this->actingAs($staff)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->json('data.recent_activity');

        $descriptions = collect($feed)->pluck('description');
        $this->assertTrue($descriptions->contains('Mine created'));
        $this->assertFalse($descriptions->contains('Theirs created'));
        $this->assertSame('lead', $feed[0]['subject']['type']);
        $this->assertSame($mine->id, $feed[0]['subject']['id']);
    }

    public function test_inactive_user_is_rejected(): void
    {
        $manager = User::factory()->manager()->inactive()->create();

        $this->actingAs($manager)
            ->getJson('/api/v1/dashboard')
            ->assertForbidden();
    }

    private function makeAppointment(
        Customer $customer,
        Service $service,
        User $staff,
        string $date,
        string $start,
        AppointmentStatus $status,
    ): Appointment {
        return Appointment::factory()->create([
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'staff_user_id' => $staff->id,
            'scheduled_date' => $date,
            'start_time' => $start,
            'end_time' => '12:00:00',
            'status' => $status,
        ]);
    }
}
