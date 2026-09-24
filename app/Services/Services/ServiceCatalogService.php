<?php

namespace App\Services\Services;

use App\Models\Service;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ServiceCatalogService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Service>
     */
    public function paginate(User $actor, array $filters = []): LengthAwarePaginator
    {
        return Service::query()
            ->when(
                $actor->isStaff(),
                fn ($query) => $query->active(),
                fn ($query) => $query->when(
                    array_key_exists('is_active', $filters),
                    fn ($filtered) => $filtered->where('is_active', $filters['is_active']),
                ),
            )
            ->orderBy('name')
            ->paginate(15);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Service
    {
        return Service::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Service $service, array $attributes): Service
    {
        $service->update($attributes);

        return $service->refresh();
    }

    public function activate(Service $service): Service
    {
        $service->update(['is_active' => true]);

        return $service->refresh();
    }

    public function deactivate(Service $service): Service
    {
        $service->update(['is_active' => false]);

        return $service->refresh();
    }
}
