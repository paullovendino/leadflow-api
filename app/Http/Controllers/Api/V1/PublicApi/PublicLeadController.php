<?php

namespace App\Http\Controllers\Api\V1\PublicApi;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PublicApi\StorePublicLeadRequest;
use App\Http\Resources\Api\V1\PublicLeadResource;
use App\Services\Crm\LeadService;
use Illuminate\Http\JsonResponse;

class PublicLeadController extends Controller
{
    public function store(StorePublicLeadRequest $request, LeadService $leads): JsonResponse
    {
        return (new PublicLeadResource($leads->createPublicLead($request->safe()->except('company'))))
            ->response()
            ->setStatusCode(201);
    }
}
