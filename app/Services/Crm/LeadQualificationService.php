<?php

namespace App\Services\Crm;

use App\Enums\ActivityType;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeadQualificationService
{
    public function __construct(
        private readonly ActivityService $activities,
        private readonly PipelineService $pipelines,
    ) {}

    /**
     * @return array{lead: Lead, customer_created: bool, already_qualified: bool}
     */
    public function qualifyLead(User $actor, Lead $lead): array
    {
        return DB::transaction(function () use ($actor, $lead) {
            $lead = Lead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail();
            $lead->load('pipelineStage');

            if ($this->isQualified($lead) && $lead->customer_id !== null) {
                return [
                    'lead' => $this->detail($lead),
                    'customer_created' => false,
                    'already_qualified' => true,
                ];
            }

            if ($lead->customer_id !== null || $lead->pipelineStage?->slug === 'converted') {
                throw ValidationException::withMessages([
                    'lead' => ['This lead has already been converted.'],
                ]);
            }

            if (! $this->isContacted($lead) && ! $this->isQualified($lead)) {
                throw ValidationException::withMessages([
                    'lead' => ['Only contacted leads can be qualified.'],
                ]);
            }

            if (blank($lead->email) && blank($lead->phone)) {
                throw ValidationException::withMessages([
                    'lead' => ['A lead must have an email or phone number before it can be qualified.'],
                ]);
            }

            $match = $this->resolveMatchingCustomer($lead);
            $customerCreated = $match === null;

            $customer = $match ?? Customer::query()->create([
                'name' => $lead->name,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'source' => $lead->source,
            ]);

            $qualifiedStage = $this->qualifiedStage($lead);

            $lead->update([
                'customer_id' => $customer->id,
                'pipeline_stage_id' => $qualifiedStage->id,
            ]);

            $this->activities->record(
                $lead,
                $actor,
                ActivityType::LeadQualified,
                $customerCreated
                    ? 'Lead qualified and customer created'
                    : 'Lead qualified and linked to customer',
                [
                    'customer_id' => $customer->id,
                    'customer_name' => $customer->name,
                    'customer_created' => $customerCreated,
                ],
            );

            if ($customerCreated) {
                $this->activities->record(
                    $customer,
                    $actor,
                    ActivityType::CustomerCreated,
                    'Customer created from lead qualification',
                    [
                        'lead_id' => $lead->id,
                        'lead_name' => $lead->name,
                    ],
                );
            }

            return [
                'lead' => $this->detail($lead),
                'customer_created' => $customerCreated,
                'already_qualified' => false,
            ];
        });
    }

    private function resolveMatchingCustomer(Lead $lead): ?Customer
    {
        $email = $this->normalizedEmail($lead->email);
        $phoneKey = $this->phoneMatchKey($lead->phone);

        if ($email === null && $phoneKey === null) {
            return null;
        }

        $candidates = Customer::query()
            ->where(function ($query) use ($email, $phoneKey) {
                if ($email !== null) {
                    $query->orWhereRaw('LOWER(email) = ?', [$email]);
                }

                if ($phoneKey !== null) {
                    $query->orWhereNotNull('phone');
                }
            })
            ->get();

        $emailMatches = $email === null
            ? collect()
            : $candidates->filter(fn (Customer $customer) => $this->normalizedEmail($customer->email) === $email);

        $phoneMatches = $phoneKey === null
            ? collect()
            : $candidates->filter(fn (Customer $customer) => $this->phoneMatchKey($customer->phone) === $phoneKey);

        $matched = Collection::make()
            ->merge($emailMatches)
            ->merge($phoneMatches)
            ->unique('id')
            ->values();

        if ($matched->count() > 1) {
            throw ValidationException::withMessages([
                'lead' => ['This lead matches more than one customer. Resolve the conflicting records before qualifying.'],
            ]);
        }

        return $matched->first();
    }

    private function qualifiedStage(Lead $lead): PipelineStage
    {
        $pipelineId = $lead->pipelineStage?->pipeline_id
            ?? $this->pipelines->defaultPipeline()->id;

        $stage = PipelineStage::query()
            ->where('pipeline_id', $pipelineId)
            ->where('slug', 'qualified')
            ->where('is_active', true)
            ->first();

        if ($stage === null) {
            throw ValidationException::withMessages([
                'lead' => ['The Qualified pipeline stage is not available.'],
            ]);
        }

        return $stage;
    }

    private function isContacted(Lead $lead): bool
    {
        return $lead->pipelineStage?->slug === 'contacted';
    }

    private function isQualified(Lead $lead): bool
    {
        return $lead->pipelineStage?->slug === 'qualified';
    }

    private function normalizedEmail(?string $email): ?string
    {
        $value = strtolower(trim((string) $email));

        return $value === '' ? null : $value;
    }

    private function phoneMatchKey(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if (strlen($digits) < 7) {
            return null;
        }

        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }

    private function detail(Lead $lead): Lead
    {
        return $lead->refresh()->load([
            'service',
            'assignedUser',
            'pipelineStage.pipeline',
            'customer',
            'notes.user',
            'activities.user',
        ]);
    }
}
