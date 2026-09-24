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

class CustomerManagementTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDefaultPipeline;

    public function test_administrator_can_create_a_customer(): void
    {
        $administrator = User::factory()->administrator()->create();

        $this->actingAs($administrator)
            ->postJson('/api/v1/customers', [
                'name' => 'Jane Customer',
                'email' => 'jane.customer@example.com',
                'source' => LeadSource::Website->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Jane Customer')
            ->assertJsonPath('data.email', 'jane.customer@example.com');
    }

    public function test_manager_can_create_a_customer(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)
            ->postJson('/api/v1/customers', [
                'name' => 'Walk In Customer',
                'phone' => '09170001111',
            ])
            ->assertCreated()
            ->assertJsonPath('data.phone', '09170001111');
    }

    public function test_staff_cannot_create_an_unrelated_customer(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)
            ->postJson('/api/v1/customers', [
                'name' => 'Blocked',
                'email' => 'blocked@example.com',
            ])
            ->assertForbidden();
    }

    public function test_customer_requires_name_and_email_or_phone(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)
            ->postJson('/api/v1/customers', [
                'email' => 'missing.name@example.com',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->actingAs($manager)
            ->postJson('/api/v1/customers', [
                'name' => 'No Contact',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'phone']);
    }

    public function test_customers_can_be_listed_searched_filtered_and_paginated(): void
    {
        $manager = User::factory()->manager()->create();
        Customer::factory()->create([
            'name' => 'Alpha Clinic',
            'email' => 'alpha@example.com',
            'source' => LeadSource::Website,
        ]);
        Customer::factory()->create([
            'name' => 'Beta Studio',
            'email' => 'beta@example.com',
            'source' => LeadSource::Facebook,
        ]);
        Customer::factory()->count(16)->create();

        $this->actingAs($manager)
            ->getJson('/api/v1/customers?search=Alpha')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alpha Clinic');

        $this->actingAs($manager)
            ->getJson('/api/v1/customers?source=facebook')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Beta Studio');

        $this->actingAs($manager)
            ->getJson('/api/v1/customers?per_page=10')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.current_page', 1);
    }

    public function test_authorized_user_can_view_and_update_a_customer(): void
    {
        $manager = User::factory()->manager()->create();
        $customer = Customer::factory()->create([
            'name' => 'Old Name',
            'email' => 'old@example.com',
        ]);

        $this->actingAs($manager)
            ->getJson("/api/v1/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $customer->id);

        $this->actingAs($manager)
            ->patchJson("/api/v1/customers/{$customer->id}", [
                'name' => 'New Name',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name');
    }

    public function test_staff_cannot_view_an_unrelated_customer(): void
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

        $this->actingAs($staff)
            ->getJson("/api/v1/customers/{$customer->id}")
            ->assertForbidden();

        $this->actingAs($staff)
            ->patchJson("/api/v1/customers/{$customer->id}", [
                'name' => 'Nope',
            ])
            ->assertForbidden();
    }

    public function test_staff_can_view_a_customer_from_an_assigned_lead(): void
    {
        $this->seedDefaultPipeline();
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create(['name' => 'Assigned Customer']);
        Lead::factory()->create([
            'assigned_user_id' => $staff->id,
            'customer_id' => $customer->id,
            'pipeline_stage_id' => $this->stageBySlug('converted')->id,
        ]);

        $this->actingAs($staff)
            ->getJson("/api/v1/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Assigned Customer')
            ->assertJsonCount(1, 'data.leads');
    }

    public function test_staff_list_only_includes_customers_from_assigned_leads(): void
    {
        $this->seedDefaultPipeline();
        $staff = User::factory()->staff()->create();
        $visible = Customer::factory()->create(['name' => 'Visible Customer']);
        $hidden = Customer::factory()->create(['name' => 'Hidden Customer']);
        Lead::factory()->create([
            'assigned_user_id' => $staff->id,
            'customer_id' => $visible->id,
            'pipeline_stage_id' => $this->stageBySlug('converted')->id,
        ]);
        Lead::factory()->create([
            'assigned_user_id' => User::factory()->staff()->create()->id,
            'customer_id' => $hidden->id,
            'pipeline_stage_id' => $this->stageBySlug('converted')->id,
        ]);

        $this->actingAs($staff)
            ->getJson('/api/v1/customers')
            ->assertOk()
            ->assertJsonFragment(['id' => $visible->id])
            ->assertJsonMissing(['id' => $hidden->id]);
    }

    public function test_customer_notes_and_activities_are_available(): void
    {
        $manager = User::factory()->manager()->create();
        $customer = Customer::factory()->create();

        $this->actingAs($manager)
            ->postJson("/api/v1/customers/{$customer->id}/notes", [
                'body' => 'Preferred morning appointments.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Preferred morning appointments.');

        $this->actingAs($manager)
            ->getJson("/api/v1/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.notes.0.body', 'Preferred morning appointments.');

        $this->assertDatabaseHas('activities', [
            'activityable_id' => $customer->id,
            'activityable_type' => 'customer',
            'type' => ActivityType::NoteAdded->value,
        ]);

        $this->actingAs($manager)
            ->getJson("/api/v1/customers/{$customer->id}/activities")
            ->assertOk()
            ->assertJsonFragment(['type' => ActivityType::NoteAdded->value]);
    }

    public function test_customer_update_cannot_clear_all_contact_methods(): void
    {
        $manager = User::factory()->manager()->create();
        $customer = Customer::factory()->create([
            'email' => 'keep@example.com',
            'phone' => null,
        ]);

        $this->actingAs($manager)
            ->patchJson("/api/v1/customers/{$customer->id}", [
                'email' => null,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }
}
