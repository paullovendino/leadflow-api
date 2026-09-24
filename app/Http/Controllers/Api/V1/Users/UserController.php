<?php

namespace App\Http\Controllers\Api\V1\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Users\IndexUserRequest;
use App\Http\Requests\Api\V1\Users\StoreUserRequest;
use App\Http\Requests\Api\V1\Users\UpdateUserRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\Users\UserManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function index(IndexUserRequest $request, UserManagementService $users): AnonymousResourceCollection
    {
        return UserResource::collection($users->paginate($request->validated()));
    }

    public function store(StoreUserRequest $request, UserManagementService $users): JsonResponse
    {
        return (new UserResource($users->create($request->validated())))
            ->response()
            ->setStatusCode(201);
    }

    public function show(User $user): UserResource
    {
        $this->authorize('view', $user);

        return new UserResource($user);
    }

    public function update(UpdateUserRequest $request, User $user, UserManagementService $users): UserResource
    {
        return new UserResource($users->update($user, $request->validated()));
    }

    public function activate(User $user, UserManagementService $users): UserResource
    {
        $this->authorize('activate', $user);

        return new UserResource($users->activate($user));
    }

    public function deactivate(User $user, UserManagementService $users): UserResource
    {
        $this->authorize('deactivate', $user);

        return new UserResource($users->deactivate($user));
    }
}
