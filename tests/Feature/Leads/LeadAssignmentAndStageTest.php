<?php

namespace Tests\Feature\Leads;

use App\Enums\ActivityType;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\SeedsDefaultPipeline;
use Tests\TestCase;

class LeadAssignmentAndStageTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDefaultPipeline;

    public function test_manager_can_assign_a_lead(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        $staff = User::factory()->staff()->create(['name' => 'John Smith']);
        $lead = Lead::factory()->create([
            'pipeline_stage_id' => $this->stageBySlug('new')->id,
        ]);

        $this->actingAs($manager)
            ->patchJson("/api/v1/leads/{$lead->id}/assignment", [
                'assigned_user_id' => $staff->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.assigned_user.id', $staff->id);

        $this->assertDatabaseHas('activities', [
            'activityable_id' => $lead->id,
            'type' => ActivityType::LeadAssigned->value,
            'description' => 'Lead assigned to John Smith',
        ]);
    }

    public function test_administrator_can_assign_a_lead(): void
    {
        $this->seedDefaultPipeline();
        $administrator = User::factory()->administrator()->create();
        $staff = User::factory()->staff()->create();
        $lead = Lead::factory()->create([
            'pipeline_stage_id' => $this->stageBySlug('new')->id,
        ]);

        $this->actingAs($administrator)
            ->patchJson("/api/v1/leads/{$lead->id}/assignment", [
                'assigned_user_id' => $staff->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.assigned_user.id', $staff->id);
    }

    public function test_staff_cannot_arbitrarily_assign_leads(): void
    {
        $this->seedDefaultPipeline();
        $staff = User::factory()->staff()->create();
        $other = User::factory()->staff()->create();
        $lead = Lead::factory()->create([
            'assigned_user_id' => $staff->id,
            'pipeline_stage_id' => $this->stageBySlug('new')->id,
        ]);

        $this->actingAs($staff)
            ->patchJson("/api/v1/leads/{$lead->id}/assignment", [
                'assigned_user_id' => $other->id,
            ])
            ->assertForbidden();
    }

    public function test_inactive_users_cannot_be_assigned(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        $inactive = User::factory()->staff()->inactive()->create();
        $lead = Lead::factory()->create([
            'pipeline_stage_id' => $this->stageBySlug('new')->id,
        ]);

        $this->actingAs($manager)
            ->patchJson("/api/v1/leads/{$lead->id}/assignment", [
                'assigned_user_id' => $inactive->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assigned_user_id']);
    }

    public function test_authorized_user_can_move_lead_and_activity_preserves_stage_metadata(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        $new = $this->stageBySlug('new');
        $contacted = $this->stageBySlug('contacted');
        $lead = Lead::factory()->create([
            'pipeline_stage_id' => $new->id,
        ]);

        $this->actingAs($manager)
            ->patchJson("/api/v1/leads/{$lead->id}/stage", [
                'pipeline_stage_id' => $contacted->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.pipeline_stage.id', $contacted->id);

        $activity = $lead->activities()->where('type', ActivityType::StageChanged)->first();

        $this->assertNotNull($activity);
        $this->assertSame("Lead moved from {$new->name} to {$contacted->name}", $activity->description);
        $this->assertSame($new->id, $activity->metadata['old_stage_id']);
        $this->assertSame($new->name, $activity->metadata['old_stage_name']);
        $this->assertSame($contacted->id, $activity->metadata['new_stage_id']);
        $this->assertSame($contacted->name, $activity->metadata['new_stage_name']);
    }

    public function test_unauthorized_user_cannot_move_another_staff_lead(): void
    {
        $this->seedDefaultPipeline();
        $staff = User::factory()->staff()->create();
        $other = User::factory()->staff()->create();
        $lead = Lead::factory()->create([
            'assigned_user_id' => $other->id,
            'pipeline_stage_id' => $this->stageBySlug('new')->id,
        ]);

        $this->actingAs($staff)
            ->patchJson("/api/v1/leads/{$lead->id}/stage", [
                'pipeline_stage_id' => $this->stageBySlug('contacted')->id,
            ])
            ->assertForbidden();
    }

    public function test_stage_from_another_pipeline_is_rejected(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        $foreign = Pipeline::factory()->create([
            'name' => 'Other Pipeline',
            'is_default' => false,
        ]);
        $foreignStage = $foreign->stages()->create([
            'name' => 'Foreign',
            'slug' => 'foreign',
            'position' => 1,
            'is_active' => true,
        ]);
        $lead = Lead::factory()->create([
            'pipeline_stage_id' => $this->stageBySlug('new')->id,
        ]);

        $this->actingAs($manager)
            ->patchJson("/api/v1/leads/{$lead->id}/stage", [
                'pipeline_stage_id' => $foreignStage->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pipeline_stage_id']);
    }

    public function test_stage_change_through_update_uses_the_same_rules(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        $lead = Lead::factory()->create([
            'pipeline_stage_id' => $this->stageBySlug('new')->id,
        ]);
        $contacted = $this->stageBySlug('contacted');

        $this->actingAs($manager)
            ->patchJson("/api/v1/leads/{$lead->id}", [
                'pipeline_stage_id' => $contacted->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.pipeline_stage.id', $contacted->id);

        $this->assertDatabaseHas('activities', [
            'activityable_id' => $lead->id,
            'type' => ActivityType::StageChanged->value,
        ]);
    }
}
