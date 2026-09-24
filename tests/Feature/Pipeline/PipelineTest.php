<?php

namespace Tests\Feature\Pipeline;

use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\SeedsDefaultPipeline;
use Tests\TestCase;

class PipelineTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDefaultPipeline;

    public function test_default_pipeline_and_stages_exist(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)
            ->getJson('/api/v1/pipeline')
            ->assertOk()
            ->assertJsonPath('data.name', 'Default Lead Pipeline')
            ->assertJsonPath('data.is_default', true)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonCount(9, 'data.stages')
            ->assertJsonPath('data.stages.0.slug', 'new')
            ->assertJsonPath('data.stages.0.position', 1)
            ->assertJsonPath('data.stages.8.slug', 'no_response');
    }

    public function test_stage_ordering_is_by_position(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        $new = $this->stageBySlug('new');

        $this->actingAs($manager)
            ->patchJson("/api/v1/pipeline/stages/{$new->id}", [
                'position' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('data.position', 3);

        $this->actingAs($manager)
            ->getJson('/api/v1/pipeline')
            ->assertOk()
            ->assertJsonPath('data.stages.0.slug', 'contacted')
            ->assertJsonPath('data.stages.2.slug', 'new');
    }

    public function test_managers_can_activate_and_deactivate_stages(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        $lost = $this->stageBySlug('lost');

        $this->actingAs($manager)
            ->postJson("/api/v1/pipeline/stages/{$lost->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->actingAs($manager)
            ->postJson("/api/v1/pipeline/stages/{$lost->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_staff_cannot_manage_pipeline_stages(): void
    {
        $this->seedDefaultPipeline();
        $staff = User::factory()->staff()->create();
        $stage = $this->stageBySlug('qualified');

        $this->actingAs($staff)
            ->getJson('/api/v1/pipeline')
            ->assertOk();

        $this->actingAs($staff)
            ->patchJson("/api/v1/pipeline/stages/{$stage->id}", ['name' => 'Blocked'])
            ->assertForbidden();

        $this->actingAs($staff)
            ->postJson("/api/v1/pipeline/stages/{$stage->id}/deactivate")
            ->assertForbidden();
    }

    public function test_inactive_stages_cannot_receive_lead_moves(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        $inactive = $this->stageBySlug('lost');
        $inactive->update(['is_active' => false]);

        $lead = Lead::factory()->create([
            'pipeline_stage_id' => $this->stageBySlug('new')->id,
        ]);

        $this->actingAs($manager)
            ->patchJson("/api/v1/leads/{$lead->id}/stage", [
                'pipeline_stage_id' => $inactive->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pipeline_stage_id']);
    }

    public function test_seeding_does_not_create_multiple_default_pipelines(): void
    {
        $this->seedDefaultPipeline();
        $this->seedDefaultPipeline();

        $this->assertSame(1, Pipeline::query()->where('is_default', true)->count());
        $this->assertSame(9, PipelineStage::query()->count());
    }
}
