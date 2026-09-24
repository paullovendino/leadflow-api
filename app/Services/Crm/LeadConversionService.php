<?php

namespace App\Services\Crm;

use App\Enums\ActivityType;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeadConversionService
{
    public function __construct(
        private readonly ActivityService $activities,
        private readonly PipelineService $pipelines,
    ) {}

    public function convertLead(User $actor, Lead $lead): Lead
    {
        return DB::transaction(function () use ($actor, $lead) {
            $lead->refresh();

            if ($lead->customer_id !== null) {
                throw ValidationException::withMessages([
                    'lead' => ['This lead has already been converted.'],
                ]);
            }

            $customer = Customer::query()->create([
                'name' => $lead->name,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'source' => $lead->source,
            ]);

            $convertedStage = $this->convertedStage($lead);

            $lead->update([
                'customer_id' => $customer->id,
                'pipeline_stage_id' => $convertedStage->id,
            ]);

            $this->activities->record(
                $lead,
                $actor,
                ActivityType::LeadConverted,
                'Lead converted to customer',
                [
                    'customer_id' => $customer->id,
                    'customer_name' => $customer->name,
                ],
            );

            $this->activities->record(
                $customer,
                $actor,
                ActivityType::CustomerCreated,
                'Customer created from lead conversion',
                [
                    'lead_id' => $lead->id,
                    'lead_name' => $lead->name,
                ],
            );

            return $lead->refresh()->load([
                'service',
                'assignedUser',
                'pipelineStage.pipeline',
                'customer',
                'notes.user',
                'activities.user',
            ]);
        });
    }

    private function convertedStage(Lead $lead): PipelineStage
    {
        $lead->loadMissing('pipelineStage');

        $pipelineId = $lead->pipelineStage?->pipeline_id
            ?? $this->pipelines->defaultPipeline()->id;

        $stage = PipelineStage::query()
            ->where('pipeline_id', $pipelineId)
            ->where('slug', 'converted')
            ->where('is_active', true)
            ->first();

        if ($stage === null) {
            throw ValidationException::withMessages([
                'lead' => ['The Converted pipeline stage is not available.'],
            ]);
        }

        return $stage;
    }
}
