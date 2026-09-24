<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrators_bypass_gates(): void
    {
        $administrator = User::factory()->administrator()->create();

        $this->assertTrue($administrator->isAdministrator());
        $this->assertTrue(Gate::forUser($administrator)->allows('any-ability'));
    }

    public function test_non_administrators_do_not_bypass_gates(): void
    {
        $staff = User::factory()->staff()->create();

        $this->assertFalse($staff->isAdministrator());
        $this->assertFalse(Gate::forUser($staff)->allows('any-ability'));
    }

    public function test_users_can_check_assigned_roles(): void
    {
        $manager = User::factory()->manager()->create();

        $this->assertTrue($manager->hasRole(UserRole::Manager));
        $this->assertTrue($manager->hasRole('manager'));
        $this->assertFalse($manager->hasRole(UserRole::Staff));
        $this->assertTrue($manager->isManager());
        $this->assertFalse($manager->isStaff());
    }

    public function test_inactive_authenticated_users_are_rejected(): void
    {
        $user = User::factory()->inactive()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/auth/user')
            ->assertForbidden()
            ->assertJsonPath('message', 'This account is inactive.');
    }
}
