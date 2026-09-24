<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Services\Auth\AuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function login(LoginRequest $request, AuthenticationService $authentication): UserResource
    {
        return new UserResource($authentication->login($request));
    }

    public function logout(Request $request, AuthenticationService $authentication): JsonResponse
    {
        $authentication->logout($request);

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    public function user(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
