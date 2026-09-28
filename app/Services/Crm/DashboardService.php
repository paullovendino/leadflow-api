<?php

namespace App\Services\Crm;

use App\Enums\AppointmentStatus;
use App\Models\Activity;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class DashboardService
{
    private const RECENT_LEADS = 8;

    private const RECENT_ACTIVITIES = 10;

    /**
     * @return array<string, mixed>
     */
    public function forUser(User $actor): array
    {
        $now = now();
        $today = $now->toDateString();
        $pipeline = $this->defaultPipeline();
        $stageCounts = $this->leadStageCounts($actor);
        $qualifiedId = $this->stageIdBySlug($pipeline, 'qualified');
        $convertedId = $this->stageIdBySlug($pipeline, 'converted');

        return [
            'overview' => [
                'total_leads' => $this->leadQuery($actor)->count(),
                'qualified_leads' => $qualifiedId === null ? 0 : (int) ($stageCounts[$qualifiedId] ?? 0),
                'converted_leads' => $convertedId === null ? 0 : (int) ($stageCounts[$convertedId] ?? 0),
                'total_customers' => $this->customerQuery($actor)->count(),
            ],
            'appointments' => $this->appointmentOverview($actor, $now, $today),
            'pipeline' => $this->pipelineMetrics($pipeline, $stageCounts),
            'lead_sources' => $this->leadSources($actor),
            'appointment_breakdown' => $this->appointmentBreakdown($actor),
            'recent_leads' => $this->recentLeads($actor),
            'recent_activity' => $this->recentActivity($actor),
        ];
    }

    /**
     * @return Builder<Lead>
     */
    private function leadQuery(User $actor): Builder
    {
        return Lead::query()
            ->when($actor->isStaff(), fn ($query) => $query->where('assigned_user_id', $actor->id));
    }

    /**
     * @return Builder<Customer>
     */
    private function customerQuery(User $actor): Builder
    {
        return Customer::query()
            ->when(
                $actor->isStaff(),
                fn ($query) => $query->whereHas(
                    'leads',
                    fn ($leads) => $leads->where('assigned_user_id', $actor->id),
                ),
            );
    }

    /**
     * @return Builder<Appointment>
     */
    private function appointmentQuery(User $actor): Builder
    {
        return Appointment::query()
            ->when($actor->isStaff(), function ($query) use ($actor) {
                $query->where(function ($scoped) use ($actor) {
                    $scoped->where('staff_user_id', $actor->id)
                        ->orWhereHas(
                            'customer.leads',
                            fn ($leads) => $leads->where('assigned_user_id', $actor->id),
                        );
                });
            });
    }

    private function defaultPipeline(): ?Pipeline
    {
        return Pipeline::query()
            ->default()
            ->with(['stages' => fn ($query) => $query->active()->orderBy('position')->orderBy('id')])
            ->first();
    }

    /**
     * @return array<int, int>
     */
    private function leadStageCounts(User $actor): array
    {
        return $this->leadQuery($actor)
            ->selectRaw('pipeline_stage_id, count(*) as aggregate')
            ->groupBy('pipeline_stage_id')
            ->pluck('aggregate', 'pipeline_stage_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    private function stageIdBySlug(?Pipeline $pipeline, string $slug): ?int
    {
        $id = $pipeline?->stages->firstWhere('slug', $slug)?->id;

        return $id === null ? null : (int) $id;
    }

    /**
     * @param  array<int, int>  $stageCounts
     * @return list<array{id: int, name: string, slug: string, position: int, count: int}>
     */
    private function pipelineMetrics(?Pipeline $pipeline, array $stageCounts): array
    {
        if ($pipeline === null) {
            return [];
        }

        return $pipeline->stages->map(fn (PipelineStage $stage) => [
            'id' => $stage->id,
            'name' => $stage->name,
            'slug' => $stage->slug,
            'position' => $stage->position,
            'count' => $stageCounts[$stage->id] ?? 0,
        ])->values()->all();
    }

    /**
     * @return list<array{source: ?string, count: int}>
     */
    private function leadSources(User $actor): array
    {
        return $this->leadQuery($actor)
            ->selectRaw('source, count(*) as aggregate')
            ->groupBy('source')
            ->get()
            ->sortBy([
                ['aggregate', 'desc'],
                ['source', 'asc'],
            ])
            ->values()
            ->map(fn ($row) => [
                'source' => $row->source instanceof \BackedEnum ? $row->source->value : ($row->source ?: null),
                'count' => (int) $row->aggregate,
            ])
            ->all();
    }

    /**
     * @return array{
     *     today: int,
     *     upcoming: int,
     *     scheduled: int,
     *     confirmed: int,
     *     completed: int,
     *     cancelled: int,
     *     no_show: int
     * }
     */
    private function appointmentOverview(User $actor, Carbon $now, string $today): array
    {
        $statusCounts = $this->appointmentQuery($actor)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count);

        return [
            'today' => $this->appointmentQuery($actor)->whereDate('scheduled_date', $today)->count(),
            'upcoming' => $this->upcomingAppointments($actor, $now)->count(),
            'scheduled' => (int) ($statusCounts[AppointmentStatus::Scheduled->value] ?? 0),
            'confirmed' => (int) ($statusCounts[AppointmentStatus::Confirmed->value] ?? 0),
            'completed' => (int) ($statusCounts[AppointmentStatus::Completed->value] ?? 0),
            'cancelled' => (int) ($statusCounts[AppointmentStatus::Cancelled->value] ?? 0),
            'no_show' => (int) ($statusCounts[AppointmentStatus::NoShow->value] ?? 0),
        ];
    }

    /**
     * @return Builder<Appointment>
     */
    private function upcomingAppointments(User $actor, Carbon $now): Builder
    {
        $today = $now->toDateString();
        $time = $now->format('H:i:s');

        return $this->appointmentQuery($actor)
            ->whereNotIn('status', [
                AppointmentStatus::Cancelled->value,
                AppointmentStatus::Completed->value,
                AppointmentStatus::NoShow->value,
            ])
            ->where(function ($query) use ($today, $time) {
                $query->whereDate('scheduled_date', '>', $today)
                    ->orWhere(function ($sameDay) use ($today, $time) {
                        $sameDay->whereDate('scheduled_date', $today)
                            ->where('start_time', '>=', $time);
                    });
            });
    }

    /**
     * @return array{
     *     by_status: list<array{status: string, count: int}>,
     *     by_service: list<array{service_id: int, name: string, count: int}>,
     *     by_staff: list<array{staff_user_id: int, name: string, count: int}>
     * }
     */
    private function appointmentBreakdown(User $actor): array
    {
        $byStatus = collect(AppointmentStatus::cases())
            ->map(fn (AppointmentStatus $status) => [
                'status' => $status->value,
                'count' => $this->appointmentQuery($actor)->where('status', $status->value)->count(),
            ])
            ->all();

        $byService = $this->appointmentQuery($actor)
            ->join('services', 'services.id', '=', 'appointments.service_id')
            ->selectRaw('appointments.service_id, services.name as name, count(*) as aggregate')
            ->groupBy('appointments.service_id', 'services.name')
            ->orderByDesc('aggregate')
            ->orderBy('services.name')
            ->get()
            ->map(fn ($row) => [
                'service_id' => (int) $row->service_id,
                'name' => (string) $row->name,
                'count' => (int) $row->aggregate,
            ])
            ->all();

        $byStaff = $this->appointmentQuery($actor)
            ->join('users', 'users.id', '=', 'appointments.staff_user_id')
            ->selectRaw('appointments.staff_user_id, users.name as name, count(*) as aggregate')
            ->groupBy('appointments.staff_user_id', 'users.name')
            ->orderByDesc('aggregate')
            ->orderBy('users.name')
            ->get()
            ->map(fn ($row) => [
                'staff_user_id' => (int) $row->staff_user_id,
                'name' => (string) $row->name,
                'count' => (int) $row->aggregate,
            ])
            ->all();

        return [
            'by_status' => $byStatus,
            'by_service' => $byService,
            'by_staff' => $byStaff,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentLeads(User $actor): array
    {
        return $this->leadQuery($actor)
            ->with(['service', 'assignedUser', 'pipelineStage'])
            ->latest()
            ->limit(self::RECENT_LEADS)
            ->get()
            ->map(fn (Lead $lead) => [
                'id' => $lead->id,
                'name' => $lead->name,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'service' => $lead->service === null ? null : [
                    'id' => $lead->service->id,
                    'name' => $lead->service->name,
                ],
                'pipeline_stage' => $lead->pipelineStage === null ? null : [
                    'id' => $lead->pipelineStage->id,
                    'name' => $lead->pipelineStage->name,
                    'slug' => $lead->pipelineStage->slug,
                ],
                'assigned_user' => $lead->assignedUser === null ? null : [
                    'id' => $lead->assignedUser->id,
                    'name' => $lead->assignedUser->name,
                ],
                'created_at' => $lead->created_at,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentActivity(User $actor): array
    {
        return $this->activityQuery($actor)
            ->with(['user', 'activityable' => function (MorphTo $morphTo) {
                $morphTo->morphWith([
                    Appointment::class => ['customer', 'service'],
                ]);
            }])
            ->latest()
            ->orderByDesc('id')
            ->limit(self::RECENT_ACTIVITIES)
            ->get()
            ->map(fn (Activity $activity) => [
                'id' => $activity->id,
                'type' => $activity->type->value,
                'description' => $activity->description,
                'created_at' => $activity->created_at,
                'user' => $activity->user === null ? null : [
                    'id' => $activity->user->id,
                    'name' => $activity->user->name,
                ],
                'subject' => $this->activitySubject($activity),
            ])
            ->all();
    }

    /**
     * @return Builder<Activity>
     */
    private function activityQuery(User $actor): Builder
    {
        $query = Activity::query();

        if (! $actor->isStaff()) {
            return $query;
        }

        $leadIds = $this->leadQuery($actor)->select('id');
        $customerIds = $this->customerQuery($actor)->select('id');
        $appointmentIds = $this->appointmentQuery($actor)->select('id');

        return $query->where(function ($visible) use ($leadIds, $customerIds, $appointmentIds) {
            $visible->where(function ($leads) use ($leadIds) {
                $leads->where('activityable_type', 'lead')
                    ->whereIn('activityable_id', $leadIds);
            })->orWhere(function ($customers) use ($customerIds) {
                $customers->where('activityable_type', 'customer')
                    ->whereIn('activityable_id', $customerIds);
            })->orWhere(function ($appointments) use ($appointmentIds) {
                $appointments->where('activityable_type', 'appointment')
                    ->whereIn('activityable_id', $appointmentIds);
            });
        });
    }

    /**
     * @return array{type: string, id: int, name: string}|null
     */
    private function activitySubject(Activity $activity): ?array
    {
        $subject = $activity->activityable;

        if ($subject instanceof Lead) {
            return ['type' => 'lead', 'id' => $subject->id, 'name' => $subject->name];
        }

        if ($subject instanceof Customer) {
            return ['type' => 'customer', 'id' => $subject->id, 'name' => $subject->name];
        }

        if ($subject instanceof Appointment) {
            $name = $subject->customer?->name
                ?? $subject->service?->name
                ?? 'Appointment';

            return ['type' => 'appointment', 'id' => $subject->id, 'name' => $name];
        }

        return null;
    }
}
