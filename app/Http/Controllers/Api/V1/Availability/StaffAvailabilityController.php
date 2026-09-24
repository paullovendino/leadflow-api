<?php

namespace App\Http\Controllers\Api\V1\Availability;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Availability\IndexAvailabilityRequest;
use App\Http\Requests\Api\V1\Availability\StoreAvailabilityRequest;
use App\Http\Requests\Api\V1\Availability\UpdateAvailabilityRequest;
use App\Http\Resources\Api\V1\StaffAvailabilityResource;
use App\Models\StaffAvailability;
use App\Models\User;
use App\Services\Availability\StaffAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StaffAvailabilityController extends Controller
{
    public function index(IndexAvailabilityRequest $request, User $user, StaffAvailabilityService $availabilities): AnonymousResourceCollection
    {
        return StaffAvailabilityResource::collection(
            $availabilities->listForUser($user, $request->validated()),
        );
    }

    public function store(StoreAvailabilityRequest $request, User $user, StaffAvailabilityService $availabilities): JsonResponse
    {
        return (new StaffAvailabilityResource($availabilities->create($user, $request->validated())))
            ->response()
            ->setStatusCode(201);
    }

    public function show(User $user, StaffAvailability $availability): StaffAvailabilityResource
    {
        $this->authorize('view', $availability);

        return new StaffAvailabilityResource($availability);
    }

    public function update(
        UpdateAvailabilityRequest $request,
        User $user,
        StaffAvailability $availability,
        StaffAvailabilityService $availabilities,
    ): StaffAvailabilityResource {
        return new StaffAvailabilityResource($availabilities->update($availability, $request->validated()));
    }

    public function activate(User $user, StaffAvailability $availability, StaffAvailabilityService $availabilities): StaffAvailabilityResource
    {
        $this->authorize('activate', $availability);

        return new StaffAvailabilityResource($availabilities->activate($availability));
    }

    public function deactivate(User $user, StaffAvailability $availability, StaffAvailabilityService $availabilities): StaffAvailabilityResource
    {
        $this->authorize('deactivate', $availability);

        return new StaffAvailabilityResource($availabilities->deactivate($availability));
    }
}
