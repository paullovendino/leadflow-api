<?php

namespace Tests\Feature\Customers;

use App\Enums\ActivityType;
use App\Enums\LeadSource;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\SeedsDefaultPipeline;
use Tests\TestCase;

class LeadConversionTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDefaultPipeline;

    public function test_eligible_lead_can_be_converted(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        $lead = Lead::factory()->create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'source' => LeadSource::Facebook,
            'pipeline_stage_id' => $this->stageBySlug('qualified')->id,
        ]);

        $this->actingAs($manager)
            ->postJson("/api/v1/leads/{$lead->id}/convert")
            ->assertOk()
            ->assertJsonPath('data.customer.name', 'Jane Doe')
            ->assertJsonPath('data.customer.source', 'facebook')
            ->assertJsonPath('data.pipeline_stage.slug', 'converted');

        $lead->refresh();

        $this->assertNotNull($lead->customer_id);
        $this->assertSame($this->stageBySlug('converted')->id, $lead->pipeline_stage_id);
        $this->assertDatabaseHas('activities', [
            'activityable_id' => $lead->id,
            'activityable_type' => 'lead',
            'type' => ActivityType::LeadConverted->value,
            'description' => 'Lead converted to customer',
        ]);
        $this->assertDatabaseHas('activities', [
            'activityable_id' => $lead->customer_id,
            'activityable_type' => 'customer',
            'type' => ActivityType::CustomerCreated->value,
        ]);
    }

    public function test_conversion_is_idempotent(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        $lead = Lead::factory()->create([
            'pipeline_stage_id' => $this->stageBySlug('new')->id,
        ]);

        $this->actingAs($manager)
            ->postJson("/api/v1/leads/{$lead->id}/convert")
            ->assertOk();

        $customerId = $lead->refresh()->customer_id;

        $this->actingAs($manager)
            ->postJson("/api/v1/leads/{$lead->id}/convert")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lead']);

        $this->assertSame(1, Customer::query()->count());
        $this->assertSame($customerId, $lead->refresh()->customer_id);
    }

    public function test_staff_can_convert_an_assigned_lead(): void
    {
        $this->seedDefaultPipeline();
        $staff = User::factory()->staff()->create();
        $lead = Lead::factory()->create([
            'assigned_user_id' => $staff->id,
            'pipeline_stage_id' => $this->stageBySlug('contacted')->id,
        ]);

        $this->actingAs($staff)
            ->postJson("/api/v1/leads/{$lead->id}/convert")
            ->assertOk()
            ->assertJsonPath('data.pipeline_stage.slug', 'converted');
    }

    public function test_staff_cannot_convert_an_unassigned_lead(): void
    {
        $this->seedDefaultPipeline();
        $staff = User::factory()->staff()->create();
        $lead = Lead::factory()->create([
            'assigned_user_id' => User::factory()->staff()->create()->id,
            'pipeline_stage_id' => $this->stageBySlug('qualified')->id,
        ]);

        $this->actingAs($staff)
            ->postJson("/api/v1/leads/{$lead->id}/convert")
            ->assertForbidden();

        $this->assertSame(0, Customer::query()->count());
    }

    public function test_unauthenticated_user_cannot_convert_a_lead(): void
    {
        $this->seedDefaultPipeline();
        $lead = Lead::factory()->create([
            'pipeline_stage_id' => $this->stageBySlug('new')->id,
        ]);

        $this->postJson("/api/v1/leads/{$lead->id}/convert")
            ->assertUnauthorized();
    }

    public function test_conversion_preserves_customer_id_in_lead_activity_metadata(): void
    {
        $this->seedDefaultPipeline();
        $administrator = User::factory()->administrator()->create();
        $lead = Lead::factory()->create([
            'pipeline_stage_id' => $this->stageBySlug('new')->id,
        ]);

        $this->actingAs($administrator)
            ->postJson("/api/v1/leads/{$lead->id}/convert")
            ->assertOk();

        $activity = $lead->activities()->where('type', ActivityType::LeadConverted)->first();

        $this->assertNotNull($activity);
        $this->assertSame($lead->refresh()->customer_id, $activity->metadata['customer_id']);
    }

    public function test_converted_lead_detail_includes_customer_and_related_lead_on_customer(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        $lead = Lead::factory()->create([
            'name' => 'Related Lead',
            'pipeline_stage_id' => $this->stageBySlug('new')->id,
        ]);

        $response = $this->actingAs($manager)
            ->postJson("/api/v1/leads/{$lead->id}/convert")
            ->assertOk();

        $customerId = $response->json('data.customer.id');

        $this->actingAs($manager)
            ->getJson("/api/v1/leads/{$lead->id}")
            ->assertOk()
            ->assertJsonPath('data.customer.id', $customerId);

        $this->actingAs($manager)
            ->getJson("/api/v1/customers/{$customerId}")
            ->assertOk()
            ->assertJsonPath('data.leads.0.id', $lead->id)
            ->assertJsonPath('data.leads.0.name', 'Related Lead');
    }
}
