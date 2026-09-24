<?php

namespace App\Http\Controllers\Api\V1\Services;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Services\IndexServiceRequest;
use App\Http\Requests\Api\V1\Services\StoreServiceRequest;
use App\Http\Requests\Api\V1\Services\UpdateServiceRequest;
use App\Http\Resources\Api\V1\ServiceResource;
use App\Models\Service;
use App\Services\Services\ServiceCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ServiceController extends Controller
{
    public function index(IndexServiceRequest $request, ServiceCatalogService $services): AnonymousResourceCollection
    {
        return ServiceResource::collection(
            $services->paginate($request->user(), $request->validated()),
        );
    }

    public function store(StoreServiceRequest $request, ServiceCatalogService $services): JsonResponse
    {
        return (new ServiceResource($services->create($request->validated())))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Service $service): ServiceResource
    {
        $this->authorize('view', $service);

        return new ServiceResource($service);
    }

    public function update(UpdateServiceRequest $request, Service $service, ServiceCatalogService $services): ServiceResource
    {
        return new ServiceResource($services->update($service, $request->validated()));
    }

    public function activate(Service $service, ServiceCatalogService $services): ServiceResource
    {
        $this->authorize('activate', $service);

        return new ServiceResource($services->activate($service));
    }

    public function deactivate(Service $service, ServiceCatalogService $services): ServiceResource
    {
        $this->authorize('deactivate', $service);

        return new ServiceResource($services->deactivate($service));
    }
}
