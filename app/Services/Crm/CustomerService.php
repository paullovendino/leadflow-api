<?php

namespace App\Services\Crm;

use App\Enums\ActivityType;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CustomerService
{
    public function __construct(
        private readonly ActivityService $activities,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Customer>
     */
    public function paginate(User $actor, array $filters = []): LengthAwarePaginator
    {
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 15)));

        return Customer::query()
            ->when(
                $actor->isStaff(),
                fn ($query) => $query->whereHas(
                    'leads',
                    fn ($leads) => $leads->where('assigned_user_id', $actor->id),
                ),
            )
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
            ->when(isset($filters['source']), fn ($query) => $query->where('source', $filters['source']))
            ->latest()
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $actor, array $attributes): Customer
    {
        return DB::transaction(function () use ($actor, $attributes) {
            $customer = Customer::query()->create($attributes);

            $this->activities->record(
                $customer,
                $actor,
                ActivityType::CustomerCreated,
                'Customer created',
            );

            return $customer;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Customer $customer, array $attributes): Customer
    {
        $customer->update($attributes);

        return $customer->refresh();
    }

    public function detail(Customer $customer): Customer
    {
        return $customer->load([
            'leads.pipelineStage',
            'leads.assignedUser',
            'notes.user',
            'activities.user',
            'appointments.service',
            'appointments.staffUser',
        ]);
    }
}
