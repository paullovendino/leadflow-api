<?php

namespace Tests\Feature\Leads;

use App\Enums\ActivityType;
use App\Enums\LeadSource;
use App\Models\Lead;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\SeedsDefaultPipeline;
use Tests\TestCase;

class LeadManagementTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDefaultPipeline;

    public function test_authenticated_user_can_create_a_lead(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)
            ->postJson('/api/v1/leads', [
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
                'source' => LeadSource::Website->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Jane Doe')
            ->assertJsonPath('data.email', 'jane@example.com')
            ->assertJsonPath('data.pipeline_stage.slug', 'new');
    }

    public function test_unauthenticated_user_cannot_create_a_lead(): void
    {
        $this->postJson('/api/v1/leads', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ])->assertUnauthorized();
    }

    public function test_validation_requires_name(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)
            ->postJson('/api/v1/leads', [
                'email' => 'jane@example.com',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_validation_requires_email_or_phone(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)
            ->postJson('/api/v1/leads', [
                'name' => 'Jane Doe',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'phone']);
    }

    public function test_phone_only_lead_is_accepted(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)
            ->postJson('/api/v1/leads', [
                'name' => 'Walk In',
                'phone' => '09171234567',
            ])
            ->assertCreated()
            ->assertJsonPath('data.phone', '09171234567')
            ->assertJsonPath('data.email', null);
    }

    public function test_optional_service_works(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        $service = Service::factory()->create();

        $this->actingAs($manager)
            ->postJson('/api/v1/leads', [
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
                'service_id' => $service->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.service.id', $service->id);
    }

    public function test_invalid_service_is_rejected(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)
            ->postJson('/api/v1/leads', [
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
                'service_id' => 999,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['service_id']);
    }

    public function test_inactive_service_is_rejected(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        $service = Service::factory()->inactive()->create();

        $this->actingAs($manager)
            ->postJson('/api/v1/leads', [
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
                'service_id' => $service->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['service_id']);
    }

    public function test_invalid_assignee_is_rejected(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)
            ->postJson('/api/v1/leads', [
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
                'assigned_user_id' => 999,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assigned_user_id']);
    }

    public function test_inactive_assignee_is_rejected(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        $inactive = User::factory()->staff()->inactive()->create();

        $this->actingAs($manager)
            ->postJson('/api/v1/leads', [
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
                'assigned_user_id' => $inactive->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assigned_user_id']);
    }

    public function test_lead_defaults_to_new_stage(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        $new = $this->stageBySlug('new');

        $this->actingAs($manager)
            ->postJson('/api/v1/leads', [
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
            ])
            ->assertCreated()
            ->assertJsonPath('data.pipeline_stage.id', $new->id)
            ->assertJsonPath('data.pipeline_stage.slug', 'new');
    }

    public function test_lead_can_be_retrieved_and_updated(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        $lead = Lead::factory()->create([
            'name' => 'Old Name',
            'pipeline_stage_id' => $this->stageBySlug('new')->id,
        ]);

        $this->actingAs($manager)
            ->getJson("/api/v1/leads/{$lead->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $lead->id)
            ->assertJsonPath('data.name', 'Old Name');

        $this->actingAs($manager)
            ->patchJson("/api/v1/leads/{$lead->id}", [
                'name' => 'New Name',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name');

        $this->assertDatabaseHas('activities', [
            'activityable_id' => $lead->id,
            'type' => ActivityType::LeadUpdated->value,
        ]);
    }

    public function test_staff_only_see_assigned_leads(): void
    {
        $this->seedDefaultPipeline();
        $staff = User::factory()->staff()->create();
        $other = User::factory()->staff()->create();
        $stageId = $this->stageBySlug('new')->id;

        $assigned = Lead::factory()->create([
            'assigned_user_id' => $staff->id,
            'pipeline_stage_id' => $stageId,
        ]);
        $unassigned = Lead::factory()->create([
            'assigned_user_id' => $other->id,
            'pipeline_stage_id' => $stageId,
        ]);

        $this->actingAs($staff)
            ->getJson('/api/v1/leads')
            ->assertOk()
            ->assertJsonFragment(['id' => $assigned->id])
            ->assertJsonMissing(['id' => $unassigned->id]);

        $this->actingAs($staff)
            ->getJson("/api/v1/leads/{$unassigned->id}")
            ->assertForbidden();
    }

    public function test_staff_create_assigns_the_creator(): void
    {
        $this->seedDefaultPipeline();
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)
            ->postJson('/api/v1/leads', [
                'name' => 'Self Assigned',
                'email' => 'self@example.com',
                'assigned_user_id' => User::factory()->staff()->create()->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.assigned_user.id', $staff->id);
    }

    public function test_pagination_search_and_filters_work(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        $staff = User::factory()->staff()->create();
        $service = Service::factory()->create();
        $new = $this->stageBySlug('new');
        $contacted = $this->stageBySlug('contacted');

        Lead::factory()->create([
            'name' => 'Alpha Search',
            'email' => 'alpha@example.com',
            'phone' => '09171111111',
            'source' => LeadSource::Website,
            'service_id' => $service->id,
            'assigned_user_id' => $staff->id,
            'pipeline_stage_id' => $new->id,
        ]);
        Lead::factory()->create([
            'name' => 'Beta Other',
            'email' => 'beta@example.com',
            'source' => LeadSource::Facebook,
            'pipeline_stage_id' => $contacted->id,
        ]);

        $this->actingAs($manager)
            ->getJson('/api/v1/leads?search=Alpha')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alpha Search');

        $this->actingAs($manager)
            ->getJson('/api/v1/leads?stage='.$contacted->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Beta Other');

        $this->actingAs($manager)
            ->getJson('/api/v1/leads?assigned_user='.$staff->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($manager)
            ->getJson('/api/v1/leads?service='.$service->id.'&source=website')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        Lead::factory()->count(16)->create([
            'pipeline_stage_id' => $new->id,
        ]);

        $this->actingAs($manager)
            ->getJson('/api/v1/leads?per_page=10')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.current_page', 1);
    }

    public function test_arbitrary_source_is_rejected(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)
            ->postJson('/api/v1/leads', [
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
                'source' => 'tiktok',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['source']);
    }

    public function test_lead_creation_records_activity(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();

        $response = $this->actingAs($manager)
            ->postJson('/api/v1/leads', [
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
            ])
            ->assertCreated();

        $leadId = $response->json('data.id');

        $this->assertDatabaseHas('activities', [
            'activityable_id' => $leadId,
            'activityable_type' => 'lead',
            'type' => ActivityType::LeadCreated->value,
            'description' => 'Lead created',
        ]);
    }
}
