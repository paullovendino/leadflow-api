<?php

namespace Tests\Feature\Users;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrators_can_list_staff(): void
    {
        $administrator = User::factory()->administrator()->create();
        User::factory()->staff()->create(['name' => 'Ada Staff']);

        $this->actingAs($administrator)
            ->getJson('/api/v1/users')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Ada Staff']);
    }

    public function test_managers_can_create_staff(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)
            ->postJson('/api/v1/users', [
                'name' => 'New Staff',
                'email' => 'new.staff@leadflow.test',
                'password' => 'password',
                'role' => UserRole::Staff->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'new.staff@leadflow.test')
            ->assertJsonPath('data.role', UserRole::Staff->value);

        $this->assertDatabaseHas('users', [
            'email' => 'new.staff@leadflow.test',
            'role' => UserRole::Staff->value,
        ]);
    }

    public function test_staff_cannot_manage_other_users(): void
    {
        $staff = User::factory()->staff()->create();
        $other = User::factory()->staff()->create();

        $this->actingAs($staff)
            ->getJson('/api/v1/users')
            ->assertForbidden();

        $this->actingAs($staff)
            ->postJson('/api/v1/users', [
                'name' => 'Blocked',
                'email' => 'blocked@leadflow.test',
                'password' => 'password',
                'role' => UserRole::Staff->value,
            ])
            ->assertForbidden();

        $this->actingAs($staff)
            ->patchJson("/api/v1/users/{$other->id}", [
                'name' => 'Changed',
            ])
            ->assertForbidden();
    }

    public function test_staff_can_view_their_own_profile_record(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)
            ->getJson("/api/v1/users/{$staff->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $staff->id);
    }

    public function test_authorized_users_can_deactivate_and_activate_staff(): void
    {
        $manager = User::factory()->manager()->create();
        $staff = User::factory()->staff()->create();

        $this->actingAs($manager)
            ->postJson("/api/v1/users/{$staff->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->actingAs($manager)
            ->postJson("/api/v1/users/{$staff->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_staff_cannot_deactivate_other_users(): void
    {
        $staff = User::factory()->staff()->create();
        $other = User::factory()->staff()->create();

        $this->actingAs($staff)
            ->postJson("/api/v1/users/{$other->id}/deactivate")
            ->assertForbidden();
    }

    public function test_managers_cannot_deactivate_administrators(): void
    {
        $manager = User::factory()->manager()->create();
        $administrator = User::factory()->administrator()->create();

        $this->actingAs($manager)
            ->postJson("/api/v1/users/{$administrator->id}/deactivate")
            ->assertForbidden();
    }

    public function test_invalid_role_is_rejected(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)
            ->postJson('/api/v1/users', [
                'name' => 'Invalid Role',
                'email' => 'invalid.role@leadflow.test',
                'password' => 'password',
                'role' => 'superadmin',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);
    }

    public function test_managers_cannot_assign_the_administrator_role(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)
            ->postJson('/api/v1/users', [
                'name' => 'Would Be Admin',
                'email' => 'would.be.admin@leadflow.test',
                'password' => 'password',
                'role' => UserRole::Administrator->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        $manager = User::factory()->manager()->create();
        User::factory()->staff()->create([
            'email' => 'taken@leadflow.test',
        ]);

        $this->actingAs($manager)
            ->postJson('/api/v1/users', [
                'name' => 'Duplicate',
                'email' => 'taken@leadflow.test',
                'password' => 'password',
                'role' => UserRole::Staff->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_the_last_administrator_cannot_be_deactivated(): void
    {
        $administrator = User::factory()->administrator()->create();

        $this->actingAs($administrator)
            ->postJson("/api/v1/users/{$administrator->id}/deactivate")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_active']);
    }
}
