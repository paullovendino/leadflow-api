<?php

namespace Tests\Feature\Services;

use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_create_a_service(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)
            ->postJson('/api/v1/services', [
                'name' => 'Initial Assessment',
                'description' => 'First visit',
                'duration_minutes' => 60,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Initial Assessment')
            ->assertJsonPath('data.duration_minutes', 60)
            ->assertJsonPath('data.is_active', true);
    }

    public function test_authorized_user_can_update_a_service(): void
    {
        $manager = User::factory()->manager()->create();
        $service = Service::factory()->create([
            'name' => 'Old Name',
            'duration_minutes' => 30,
        ]);

        $this->actingAs($manager)
            ->patchJson("/api/v1/services/{$service->id}", [
                'name' => 'Follow-up Session',
                'duration_minutes' => 45,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Follow-up Session')
            ->assertJsonPath('data.duration_minutes', 45);
    }

    public function test_authorized_user_can_deactivate_a_service(): void
    {
        $administrator = User::factory()->administrator()->create();
        $service = Service::factory()->create();

        $this->actingAs($administrator)
            ->postJson("/api/v1/services/{$service->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->actingAs($administrator)
            ->postJson("/api/v1/services/{$service->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_staff_cannot_create_or_update_services(): void
    {
        $staff = User::factory()->staff()->create();
        $service = Service::factory()->create();

        $this->actingAs($staff)
            ->postJson('/api/v1/services', [
                'name' => 'Blocked',
                'duration_minutes' => 30,
            ])
            ->assertForbidden();

        $this->actingAs($staff)
            ->patchJson("/api/v1/services/{$service->id}", [
                'name' => 'Still Blocked',
            ])
            ->assertForbidden();

        $this->actingAs($staff)
            ->postJson("/api/v1/services/{$service->id}/deactivate")
            ->assertForbidden();
    }

    public function test_invalid_duration_is_rejected(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)
            ->postJson('/api/v1/services', [
                'name' => 'Bad Duration',
                'duration_minutes' => 0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['duration_minutes']);
    }

    public function test_staff_only_see_active_services(): void
    {
        $staff = User::factory()->staff()->create();
        $active = Service::factory()->create(['name' => 'Active Service']);
        $inactive = Service::factory()->inactive()->create(['name' => 'Inactive Service']);

        $this->actingAs($staff)
            ->getJson('/api/v1/services')
            ->assertOk()
            ->assertJsonFragment(['id' => $active->id])
            ->assertJsonMissing(['id' => $inactive->id]);

        $this->actingAs($staff)
            ->getJson("/api/v1/services/{$inactive->id}")
            ->assertForbidden();
    }

    public function test_managers_can_list_inactive_services(): void
    {
        $manager = User::factory()->manager()->create();
        $inactive = Service::factory()->inactive()->create(['name' => 'Paused Service']);

        $this->actingAs($manager)
            ->getJson('/api/v1/services')
            ->assertOk()
            ->assertJsonFragment(['id' => $inactive->id]);
    }
}
