<?php

namespace Tests\Feature\Leads;

use App\Enums\ActivityType;
use App\Enums\LeadSource;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\SeedsDefaultPipeline;
use Tests\TestCase;

class LeadQualificationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDefaultPipeline;

    public function test_contacted_lead_is_qualified_and_creates_a_customer(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        $lead = Lead::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '09171234567',
            'source' => LeadSource::Website,
            'pipeline_stage_id' => $this->stageBySlug('contacted')->id,
        ]);

        $this->actingAs($manager)
            ->postJson("/api/v1/leads/{$lead->id}/qualify")
            ->assertOk()
            ->assertJsonPath('message', 'Lead qualified successfully. Customer created and linked.')
            ->assertJsonPath('customer_created', true)
            ->assertJsonPath('data.pipeline_stage.slug', 'qualified')
            ->assertJsonPath('data.customer.name', 'John Doe')
            ->assertJsonPath('data.customer.email', 'john@example.com')
            ->assertJsonPath('data.customer.phone', '09171234567');

        $lead->refresh();

        $this->assertNotNull($lead->customer_id);
        $this->assertSame($this->stageBySlug('qualified')->id, $lead->pipeline_stage_id);
        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(0, Appointment::query()->count());
        $this->assertDatabaseHas('activities', [
            'activityable_id' => $lead->id,
            'activityable_type' => 'lead',
            'type' => ActivityType::LeadQualified->value,
            'description' => 'Lead qualified and customer created',
        ]);
        $this->assertDatabaseHas('activities', [
            'activityable_id' => $lead->customer_id,
            'activityable_type' => 'customer',
            'type' => ActivityType::CustomerCreated->value,
            'description' => 'Customer created from lead qualification',
        ]);
    }

    public function test_existing_customer_is_linked_when_email_matches(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        $customer = Customer::factory()->create([
            'name' => 'Existing John',
            'email' => 'john@example.com',
            'phone' => '09170000000',
        ]);
        $lead = Lead::factory()->create([
            'name' => 'John Doe',
            'email' => 'JOHN@example.com',
            'phone' => '09171111111',
            'pipeline_stage_id' => $this->stageBySlug('contacted')->id,
        ]);

        $this->actingAs($manager)
            ->postJson("/api/v1/leads/{$lead->id}/qualify")
            ->assertOk()
            ->assertJsonPath('message', 'Lead qualified successfully. Existing customer linked.')
            ->assertJsonPath('customer_created', false)
            ->assertJsonPath('data.customer.id', $customer->id)
            ->assertJsonPath('data.pipeline_stage.slug', 'qualified');

        $this->assertSame(1, Customer::query()->count());
        $this->assertSame($customer->id, $lead->refresh()->customer_id);
        $this->assertDatabaseHas('activities', [
            'activityable_id' => $lead->id,
            'activityable_type' => 'lead',
            'type' => ActivityType::LeadQualified->value,
            'description' => 'Lead qualified and linked to customer',
        ]);
        $this->assertDatabaseMissing('activities', [
            'activityable_type' => 'customer',
            'type' => ActivityType::CustomerCreated->value,
        ]);
    }

    public function test_existing_customer_is_linked_when_phone_matches_and_emails_do_not_conflict(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        $customer = Customer::factory()->create([
            'name' => 'Existing Mary',
            'email' => null,
            'phone' => '09171234567',
        ]);
        $lead = Lead::factory()->create([
            'name' => 'Mary Lead',
            'email' => 'mary.lead@example.com',
            'phone' => '+63 917 123 4567',
            'pipeline_stage_id' => $this->stageBySlug('contacted')->id,
        ]);

        $this->actingAs($manager)
            ->postJson("/api/v1/leads/{$lead->id}/qualify")
            ->assertOk()
            ->assertJsonPath('customer_created', false)
            ->assertJsonPath('data.customer.id', $customer->id);

        $this->assertSame(1, Customer::query()->count());
        $this->assertSame($customer->id, $lead->refresh()->customer_id);
    }

    public function test_same_phone_with_different_emails_creates_a_new_customer(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        $existing = Customer::factory()->create([
            'name' => 'Isaac Villalva',
            'email' => 'ice@gmail.com',
            'phone' => '09123456789',
        ]);
        $lead = Lead::factory()->create([
            'name' => 'John Lovendino',
            'email' => 'john@gmail.com',
            'phone' => '09123456789',
            'pipeline_stage_id' => $this->stageBySlug('contacted')->id,
        ]);

        $this->actingAs($manager)
            ->postJson("/api/v1/leads/{$lead->id}/qualify")
            ->assertOk()
            ->assertJsonPath('customer_created', true)
            ->assertJsonPath('data.customer.name', 'John Lovendino')
            ->assertJsonPath('data.customer.email', 'john@gmail.com');

        $lead->refresh();

        $this->assertNotSame($existing->id, $lead->customer_id);
        $this->assertSame(2, Customer::query()->count());
    }

    public function test_existing_customer_is_reused_when_email_and_phone_match(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        $customer = Customer::factory()->create([
            'email' => 'both@example.com',
            'phone' => '09175555555',
        ]);
        $lead = Lead::factory()->create([
            'email' => 'BOTH@example.com',
            'phone' => '09175555555',
            'pipeline_stage_id' => $this->stageBySlug('contacted')->id,
        ]);

        $this->actingAs($manager)
            ->postJson("/api/v1/leads/{$lead->id}/qualify")
            ->assertOk()
            ->assertJsonPath('customer_created', false)
            ->assertJsonPath('data.customer.id', $customer->id);

        $this->assertSame(1, Customer::query()->count());
    }

    public function test_email_match_wins_when_phone_belongs_to_a_different_customer(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        $emailMatch = Customer::factory()->create([
            'name' => 'Customer A',
            'email' => 'john@example.com',
            'phone' => '09171111111',
        ]);
        Customer::factory()->create([
            'name' => 'Customer B',
            'email' => 'mary@example.com',
            'phone' => '09171222222',
        ]);
        $lead = Lead::factory()->create([
            'email' => 'john@example.com',
            'phone' => '09171222222',
            'pipeline_stage_id' => $this->stageBySlug('contacted')->id,
        ]);

        $this->actingAs($manager)
            ->postJson("/api/v1/leads/{$lead->id}/qualify")
            ->assertOk()
            ->assertJsonPath('customer_created', false)
            ->assertJsonPath('data.customer.id', $emailMatch->id);

        $this->assertSame($emailMatch->id, $lead->refresh()->customer_id);
        $this->assertSame(2, Customer::query()->count());
    }

    public function test_duplicate_customer_emails_are_treated_as_a_conflict(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        Customer::factory()->create(['email' => 'dup@example.com', 'phone' => '09171110001']);
        Customer::factory()->create(['email' => 'dup@example.com', 'phone' => '09171110002']);
        $lead = Lead::factory()->create([
            'email' => 'dup@example.com',
            'phone' => '09179999999',
            'pipeline_stage_id' => $this->stageBySlug('contacted')->id,
        ]);

        $this->actingAs($manager)
            ->postJson("/api/v1/leads/{$lead->id}/qualify")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lead']);

        $this->assertNull($lead->refresh()->customer_id);
        $this->assertSame(2, Customer::query()->count());
    }

    public function test_already_qualified_lead_is_idempotent(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        $lead = Lead::factory()->create([
            'email' => 'once@example.com',
            'phone' => '09173333333',
            'pipeline_stage_id' => $this->stageBySlug('contacted')->id,
        ]);

        $this->actingAs($manager)
            ->postJson("/api/v1/leads/{$lead->id}/qualify")
            ->assertOk()
            ->assertJsonPath('customer_created', true);

        $customerId = $lead->refresh()->customer_id;

        $this->actingAs($manager)
            ->postJson("/api/v1/leads/{$lead->id}/qualify")
            ->assertOk()
            ->assertJsonPath('message', 'Lead is already qualified.')
            ->assertJsonPath('customer_created', false)
            ->assertJsonPath('data.customer.id', $customerId);

        $this->assertSame(1, Customer::query()->count());
        $this->assertSame($customerId, $lead->refresh()->customer_id);
        $this->assertSame(1, $lead->activities()->where('type', ActivityType::LeadQualified)->count());
    }

    public function test_new_lead_cannot_be_qualified(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        $lead = Lead::factory()->create([
            'pipeline_stage_id' => $this->stageBySlug('new')->id,
        ]);

        $this->actingAs($manager)
            ->postJson("/api/v1/leads/{$lead->id}/qualify")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lead']);

        $this->assertSame(0, Customer::query()->count());
    }

    public function test_converted_lead_cannot_be_qualified(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        $customer = Customer::factory()->create();
        $lead = Lead::factory()->create([
            'customer_id' => $customer->id,
            'pipeline_stage_id' => $this->stageBySlug('converted')->id,
        ]);

        $this->actingAs($manager)
            ->postJson("/api/v1/leads/{$lead->id}/qualify")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lead']);

        $this->assertSame(1, Customer::query()->count());
        $this->assertSame($customer->id, $lead->refresh()->customer_id);
    }

    public function test_lead_without_email_or_phone_cannot_be_qualified(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        $lead = Lead::factory()->create([
            'email' => null,
            'phone' => null,
            'pipeline_stage_id' => $this->stageBySlug('contacted')->id,
        ]);

        $this->actingAs($manager)
            ->postJson("/api/v1/leads/{$lead->id}/qualify")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lead']);

        $this->assertSame(0, Customer::query()->count());
    }

    public function test_generic_stage_move_to_qualified_is_rejected(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        $lead = Lead::factory()->create([
            'pipeline_stage_id' => $this->stageBySlug('contacted')->id,
        ]);

        $this->actingAs($manager)
            ->patchJson("/api/v1/leads/{$lead->id}/stage", [
                'pipeline_stage_id' => $this->stageBySlug('qualified')->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pipeline_stage_id']);

        $this->assertSame($this->stageBySlug('contacted')->id, $lead->refresh()->pipeline_stage_id);
        $this->assertNull($lead->customer_id);
    }

    public function test_qualified_lead_without_customer_is_healed(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        $lead = Lead::factory()->create([
            'email' => 'heal@example.com',
            'phone' => '09174444444',
            'pipeline_stage_id' => $this->stageBySlug('qualified')->id,
        ]);

        $this->actingAs($manager)
            ->postJson("/api/v1/leads/{$lead->id}/qualify")
            ->assertOk()
            ->assertJsonPath('customer_created', true)
            ->assertJsonPath('data.pipeline_stage.slug', 'qualified');

        $this->assertNotNull($lead->refresh()->customer_id);
    }

    public function test_administrator_can_qualify_any_lead(): void
    {
        $this->seedDefaultPipeline();
        $administrator = User::factory()->administrator()->create();
        $lead = Lead::factory()->create([
            'assigned_user_id' => User::factory()->staff()->create()->id,
            'pipeline_stage_id' => $this->stageBySlug('contacted')->id,
        ]);

        $this->actingAs($administrator)
            ->postJson("/api/v1/leads/{$lead->id}/qualify")
            ->assertOk()
            ->assertJsonPath('data.pipeline_stage.slug', 'qualified');
    }

    public function test_manager_can_qualify_an_unassigned_lead(): void
    {
        $this->seedDefaultPipeline();
        $manager = User::factory()->manager()->create();
        $lead = Lead::factory()->create([
            'assigned_user_id' => null,
            'pipeline_stage_id' => $this->stageBySlug('contacted')->id,
        ]);

        $this->actingAs($manager)
            ->postJson("/api/v1/leads/{$lead->id}/qualify")
            ->assertOk();
    }

    public function test_staff_can_qualify_an_assigned_lead(): void
    {
        $this->seedDefaultPipeline();
        $staff = User::factory()->staff()->create();
        $lead = Lead::factory()->create([
            'assigned_user_id' => $staff->id,
            'pipeline_stage_id' => $this->stageBySlug('contacted')->id,
        ]);

        $this->actingAs($staff)
            ->postJson("/api/v1/leads/{$lead->id}/qualify")
            ->assertOk()
            ->assertJsonPath('data.pipeline_stage.slug', 'qualified');
    }

    public function test_staff_cannot_qualify_an_unassigned_lead(): void
    {
        $this->seedDefaultPipeline();
        $staff = User::factory()->staff()->create();
        $lead = Lead::factory()->create([
            'assigned_user_id' => User::factory()->staff()->create()->id,
            'pipeline_stage_id' => $this->stageBySlug('contacted')->id,
        ]);

        $this->actingAs($staff)
            ->postJson("/api/v1/leads/{$lead->id}/qualify")
            ->assertForbidden();

        $this->assertNull($lead->refresh()->customer_id);
        $this->assertSame(0, Customer::query()->count());
    }

    public function test_unauthenticated_user_cannot_qualify_a_lead(): void
    {
        $this->seedDefaultPipeline();
        $lead = Lead::factory()->create([
            'pipeline_stage_id' => $this->stageBySlug('contacted')->id,
        ]);

        $this->postJson("/api/v1/leads/{$lead->id}/qualify")
            ->assertUnauthorized();
    }
}
