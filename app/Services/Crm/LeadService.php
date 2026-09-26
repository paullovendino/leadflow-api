<?php

namespace App\Services\Crm;

use App\Enums\ActivityType;
use App\Enums\LeadSource;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\Service;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class LeadService
{
    public function __construct(
        private readonly ActivityService $activities,
        private readonly PipelineService $pipelines,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Lead>
     */
    public function paginate(User $actor, array $filters = []): LengthAwarePaginator
    {
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 15)));

        return Lead::query()
            ->with(['service', 'assignedUser', 'pipelineStage', 'customer'])
            ->when($actor->isStaff(), fn ($query) => $query->where('assigned_user_id', $actor->id))
            ->when(
                filled($filters['search'] ?? null),
                function ($query) use ($filters) {
                    $search = '%'.$filters['search'].'%';
                    $like = Schema::getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

                    $query->where(function ($matched) use ($search, $like) {
                        $matched->where('name', $like, $search)
                            ->orWhere('email', $like, $search)
                            ->orWhere('phone', $like, $search);
                    });
                },
            )
            ->when(isset($filters['stage']), fn ($query) => $query->where('pipeline_stage_id', $filters['stage']))
            ->when(
                array_key_exists('assigned_user', $filters) && $filters['assigned_user'] !== null,
                fn ($query) => $query->where('assigned_user_id', $filters['assigned_user']),
            )
            ->when(isset($filters['service']), fn ($query) => $query->where('service_id', $filters['service']))
            ->when(isset($filters['source']), fn ($query) => $query->where('source', $filters['source']))
            ->latest()
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createLead(User $actor, array $attributes): Lead
    {
        return DB::transaction(function () use ($actor, $attributes) {
            $stage = $this->resolveCreateStage($attributes['pipeline_stage_id'] ?? null);
            $assigneeId = $this->resolveCreateAssignee($actor, $attributes['assigned_user_id'] ?? null);

            $lead = Lead::query()->create([
                'name' => $attributes['name'],
                'email' => $attributes['email'] ?? null,
                'phone' => $attributes['phone'] ?? null,
                'service_id' => $this->resolveService($attributes['service_id'] ?? null)?->id,
                'source' => $attributes['source'] ?? null,
                'message' => $attributes['message'] ?? null,
                'assigned_user_id' => $assigneeId,
                'pipeline_stage_id' => $stage->id,
            ]);

            $this->activities->record($lead, $actor, ActivityType::LeadCreated, 'Lead created');

            if ($assigneeId !== null) {
                $assignee = User::query()->find($assigneeId);
                $this->activities->record(
                    $lead,
                    $actor,
                    ActivityType::LeadAssigned,
                    'Lead assigned to '.$assignee->name,
                    [
                        'assigned_user_id' => $assignee->id,
                        'assigned_user_name' => $assignee->name,
                    ],
                );
            }

            return $lead->load(['service', 'assignedUser', 'pipelineStage.pipeline', 'customer']);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createPublicLead(array $attributes): Lead
    {
        return DB::transaction(function () use ($attributes) {
            $stage = $this->resolveCreateStage(null);
            $service = $this->resolveService($attributes['service_id'] ?? null);

            $lead = Lead::query()->create([
                'name' => $attributes['name'],
                'email' => $attributes['email'] ?? null,
                'phone' => $attributes['phone'] ?? null,
                'service_id' => $service?->id,
                'source' => LeadSource::Website,
                'message' => $attributes['message'] ?? null,
                'assigned_user_id' => null,
                'pipeline_stage_id' => $stage->id,
            ]);

            $this->activities->record(
                $lead,
                null,
                ActivityType::LeadCreated,
                'Lead submitted from the website',
                ['source' => LeadSource::Website->value],
            );

            return $lead->load(['service', 'pipelineStage']);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateLead(User $actor, Lead $lead, array $attributes): Lead
    {
        return DB::transaction(function () use ($actor, $lead, $attributes) {
            $trackable = collect($attributes)->only([
                'name',
                'email',
                'phone',
                'service_id',
                'source',
                'message',
            ]);

            if ($trackable->has('service_id')) {
                $trackable['service_id'] = $this->resolveService($trackable['service_id'])?->id;
            }

            $lead->fill($trackable->all());

            if ($lead->isDirty($trackable->keys()->all())) {
                $changes = collect($lead->getDirty())->only($trackable->keys());
                $lead->save();
                $this->activities->record(
                    $lead,
                    $actor,
                    ActivityType::LeadUpdated,
                    'Lead updated',
                    ['changes' => $changes->all()],
                );
            } elseif ($trackable->isNotEmpty()) {
                $lead->save();
            }

            if (array_key_exists('assigned_user_id', $attributes)) {
                $nextAssignee = $attributes['assigned_user_id'] === null
                    ? null
                    : (int) $attributes['assigned_user_id'];

                if ($lead->assigned_user_id !== $nextAssignee) {
                    Gate::forUser($actor)->authorize('assign', $lead);
                    $this->assignLead($actor, $lead, $nextAssignee);
                }
            }

            if (array_key_exists('pipeline_stage_id', $attributes)) {
                $nextStageId = (int) $attributes['pipeline_stage_id'];

                if ($lead->pipeline_stage_id !== $nextStageId) {
                    $this->moveLeadToStage($actor, $lead, $nextStageId);
                }
            }

            return $lead->refresh()->load(['service', 'assignedUser', 'pipelineStage.pipeline', 'customer']);
        });
    }

    public function assignLead(User $actor, Lead $lead, ?int $userId): Lead
    {
        return DB::transaction(function () use ($actor, $lead, $userId) {
            if ($lead->assigned_user_id === $userId) {
                return $lead->load(['service', 'assignedUser', 'pipelineStage.pipeline', 'customer']);
            }

            $assignee = $this->resolveAssignee($userId);

            $lead->update(['assigned_user_id' => $assignee?->id]);

            $description = $assignee === null
                ? 'Lead unassigned'
                : 'Lead assigned to '.$assignee->name;

            $this->activities->record(
                $lead,
                $actor,
                ActivityType::LeadAssigned,
                $description,
                [
                    'assigned_user_id' => $assignee?->id,
                    'assigned_user_name' => $assignee?->name,
                ],
            );

            return $lead->refresh()->load(['service', 'assignedUser', 'pipelineStage.pipeline', 'customer']);
        });
    }

    public function moveLeadToStage(User $actor, Lead $lead, int $stageId): Lead
    {
        return DB::transaction(function () use ($actor, $lead, $stageId) {
            $lead->loadMissing('pipelineStage.pipeline');
            $newStage = PipelineStage::query()->with('pipeline')->findOrFail($stageId);

            $this->assertStageIsMovable($lead, $newStage);

            if ($lead->pipeline_stage_id === $newStage->id) {
                return $lead->load(['service', 'assignedUser', 'pipelineStage.pipeline', 'customer']);
            }

            $oldStage = $lead->pipelineStage;

            $lead->update(['pipeline_stage_id' => $newStage->id]);

            $this->activities->record(
                $lead,
                $actor,
                ActivityType::StageChanged,
                "Lead moved from {$oldStage->name} to {$newStage->name}",
                [
                    'old_stage_id' => $oldStage->id,
                    'old_stage_name' => $oldStage->name,
                    'new_stage_id' => $newStage->id,
                    'new_stage_name' => $newStage->name,
                ],
            );

            return $lead->refresh()->load(['service', 'assignedUser', 'pipelineStage.pipeline', 'customer']);
        });
    }

    public function detail(Lead $lead): Lead
    {
        return $lead->load([
            'service',
            'assignedUser',
            'pipelineStage.pipeline',
            'customer',
            'notes.user',
            'activities.user',
        ]);
    }

    private function resolveCreateStage(mixed $stageId): PipelineStage
    {
        $pipeline = $this->pipelines->defaultPipeline();

        if ($stageId === null) {
            $stage = $pipeline->stages->firstWhere('slug', 'new');

            if ($stage === null || ! $stage->is_active) {
                throw ValidationException::withMessages([
                    'pipeline_stage_id' => ['The default New stage is not available.'],
                ]);
            }

            return $stage;
        }

        $stage = PipelineStage::query()->find((int) $stageId);

        if ($stage === null || $stage->pipeline_id !== $pipeline->id) {
            throw ValidationException::withMessages([
                'pipeline_stage_id' => ['The selected stage does not belong to the default pipeline.'],
            ]);
        }

        if (! $stage->is_active) {
            throw ValidationException::withMessages([
                'pipeline_stage_id' => ['The selected pipeline stage is inactive.'],
            ]);
        }

        return $stage;
    }

    private function resolveCreateAssignee(User $actor, mixed $userId): ?int
    {
        if ($actor->isStaff()) {
            return $actor->id;
        }

        return $this->resolveAssignee($userId === null ? null : (int) $userId)?->id;
    }

    private function resolveAssignee(?int $userId): ?User
    {
        if ($userId === null) {
            return null;
        }

        $assignee = User::query()->find($userId);

        if ($assignee === null || ! $assignee->canBeAssignedLeads()) {
            throw ValidationException::withMessages([
                'assigned_user_id' => ['The selected user cannot be assigned leads.'],
            ]);
        }

        return $assignee;
    }

    private function resolveService(mixed $serviceId): ?Service
    {
        if ($serviceId === null) {
            return null;
        }

        $service = Service::query()->find((int) $serviceId);

        if ($service === null || ! $service->is_active) {
            throw ValidationException::withMessages([
                'service_id' => ['The selected service is invalid or inactive.'],
            ]);
        }

        return $service;
    }

    private function assertStageIsMovable(Lead $lead, PipelineStage $stage): void
    {
        $pipelineId = $lead->pipelineStage?->pipeline_id;

        if ($pipelineId === null || $stage->pipeline_id !== $pipelineId) {
            throw ValidationException::withMessages([
                'pipeline_stage_id' => ['The selected stage does not belong to this lead\'s pipeline.'],
            ]);
        }

        if (! $stage->is_active) {
            throw ValidationException::withMessages([
                'pipeline_stage_id' => ['The selected pipeline stage is inactive.'],
            ]);
        }
    }
}
