<?php

namespace Tests\Feature\PublicCapture;

use App\Enums\ActivityType;
use App\Enums\LeadSource;
use App\Models\Lead;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\SeedsDefaultPipeline;
use Tests\TestCase;

class PublicLeadCaptureTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDefaultPipeline;

    public function test_visitor_can_submit_a_valid_public_lead(): void
    {
        $this->seedDefaultPipeline();
        $service = Service::factory()->create([
            'name' => 'Initial Assessment',
            'duration_minutes' => 60,
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/public/leads', [
            'name' => '  John Customer  ',
            'email' => 'john.public@example.com',
            'phone' => '09171234567',
            'service_id' => $service->id,
            'message' => 'I would like to know more about this service.',
            'source' => 'facebook',
            'assigned_user_id' => User::factory()->staff()->create()->id,
            'pipeline_stage_id' => $this->stageBySlug('converted')->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'John Customer')
            ->assertJsonPath('data.email', 'john.public@example.com')
            ->assertJsonPath('data.source', LeadSource::Website->value)
            ->assertJsonPath('data.service.id', $service->id)
            ->assertJsonMissingPath('data.assigned_user')
            ->assertJsonMissingPath('data.pipeline_stage')
            ->assertJsonMissingPath('data.activities')
            ->assertJsonMissingPath('data.notes');

        $lead = Lead::query()->where('email', 'john.public@example.com')->firstOrFail();

        $this->assertNull($lead->assigned_user_id);
        $this->assertSame(LeadSource::Website, $lead->source);
        $this->assertSame('new', $lead->pipelineStage?->slug);
        $this->assertDatabaseHas('activities', [
            'activityable_id' => $lead->id,
            'activityable_type' => 'lead',
            'type' => ActivityType::LeadCreated->value,
            'user_id' => null,
        ]);
    }

    public function test_public_lead_requires_a_name(): void
    {
        $this->seedDefaultPipeline();

        $this->postJson('/api/v1/public/leads', [
            'email' => 'missing.name@example.com',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_public_lead_requires_email_or_phone(): void
    {
        $this->seedDefaultPipeline();

        $this->postJson('/api/v1/public/leads', [
            'name' => 'No Contact',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'phone']);
    }

    public function test_invalid_email_is_rejected(): void
    {
        $this->seedDefaultPipeline();

        $this->postJson('/api/v1/public/leads', [
            'name' => 'Bad Email',
            'email' => 'not-an-email',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_invalid_phone_is_rejected(): void
    {
        $this->seedDefaultPipeline();

        $this->postJson('/api/v1/public/leads', [
            'name' => 'Bad Phone',
            'phone' => 'call-me-maybe',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_phone_only_public_lead_is_accepted(): void
    {
        $this->seedDefaultPipeline();

        $this->postJson('/api/v1/public/leads', [
            'name' => 'Walk In Visitor',
            'phone' => '+63 917 123 4567',
        ])
            ->assertCreated()
            ->assertJsonPath('data.phone', '+63 917 123 4567')
            ->assertJsonPath('data.email', null);
    }

    public function test_inactive_service_is_rejected(): void
    {
        $this->seedDefaultPipeline();
        $service = Service::factory()->inactive()->create();

        $this->postJson('/api/v1/public/leads', [
            'name' => 'Jane',
            'email' => 'jane.inactive@example.com',
            'service_id' => $service->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['service_id']);
    }

    public function test_nonexistent_service_is_rejected(): void
    {
        $this->seedDefaultPipeline();

        $this->postJson('/api/v1/public/leads', [
            'name' => 'Jane',
            'email' => 'jane.missing@example.com',
            'service_id' => 99999,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['service_id']);
    }

    public function test_oversized_message_is_rejected(): void
    {
        $this->seedDefaultPipeline();

        $this->postJson('/api/v1/public/leads', [
            'name' => 'Jane',
            'email' => 'jane.long@example.com',
            'message' => str_repeat('a', 2001),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['message']);
    }

    public function test_honeypot_submission_is_rejected(): void
    {
        $this->seedDefaultPipeline();

        $this->postJson('/api/v1/public/leads', [
            'name' => 'Bot',
            'email' => 'bot@example.com',
            'company' => 'Spam Corp',
        ])
            ->assertUnprocessable();

        $this->assertDatabaseMissing('leads', ['email' => 'bot@example.com']);
    }

    public function test_authenticated_crm_lead_endpoint_remains_protected(): void
    {
        $this->postJson('/api/v1/leads', [
            'name' => 'Should Fail',
            'email' => 'fail@example.com',
        ])->assertUnauthorized();
    }

    public function test_public_services_return_only_active_catalog_fields(): void
    {
        Service::factory()->create(['name' => 'Visible Session', 'is_active' => true]);
        Service::factory()->inactive()->create(['name' => 'Hidden Session']);

        $this->getJson('/api/v1/public/services')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Visible Session'])
            ->assertJsonMissing(['name' => 'Hidden Session'])
            ->assertJsonMissingPath('data.0.is_active')
            ->assertJsonMissingPath('data.0.created_at');
    }

    public function test_public_lead_submissions_are_rate_limited(): void
    {
        $this->seedDefaultPipeline();

        for ($index = 1; $index <= 10; $index++) {
            $this->postJson('/api/v1/public/leads', [
                'name' => 'Rate Limit '.$index,
                'email' => "rate{$index}@example.com",
            ])->assertCreated();
        }

        $this->postJson('/api/v1/public/leads', [
            'name' => 'Rate Limit 11',
            'email' => 'rate11@example.com',
        ])->assertStatus(429);
    }
}
