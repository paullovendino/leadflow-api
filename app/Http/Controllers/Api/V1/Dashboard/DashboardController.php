<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Dashboard\ShowDashboardRequest;
use App\Http\Resources\Api\V1\DashboardResource;
use App\Services\Crm\DashboardService;

class DashboardController extends Controller
{
    public function show(ShowDashboardRequest $request, DashboardService $dashboard): DashboardResource
    {
        return new DashboardResource($dashboard->forUser($request->user()));
    }
}
