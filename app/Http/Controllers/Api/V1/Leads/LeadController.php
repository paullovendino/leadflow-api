<?php

namespace App\Http\Controllers\Api\V1\Leads;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Leads\AssignLeadRequest;
use App\Http\Requests\Api\V1\Leads\ConvertLeadRequest;
use App\Http\Requests\Api\V1\Leads\IndexLeadRequest;
use App\Http\Requests\Api\V1\Leads\MoveLeadStageRequest;
use App\Http\Requests\Api\V1\Leads\StoreLeadRequest;
use App\Http\Requests\Api\V1\Leads\UpdateLeadRequest;
use App\Http\Resources\Api\V1\LeadResource;
use App\Models\Lead;
use App\Services\Crm\LeadConversionService;
use App\Services\Crm\LeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LeadController extends Controller
{
    public function index(IndexLeadRequest $request, LeadService $leads): AnonymousResourceCollection
    {
        return LeadResource::collection($leads->paginate($request->user(), $request->validated()));
    }

    public function store(StoreLeadRequest $request, LeadService $leads): JsonResponse
    {
        return (new LeadResource($leads->createLead($request->user(), $request->validated())))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Lead $lead, LeadService $leads): LeadResource
    {
        $this->authorize('view', $lead);

        return new LeadResource($leads->detail($lead));
    }

    public function update(UpdateLeadRequest $request, Lead $lead, LeadService $leads): LeadResource
    {
        return new LeadResource($leads->updateLead($request->user(), $lead, $request->validated()));
    }

    public function assignment(AssignLeadRequest $request, Lead $lead, LeadService $leads): LeadResource
    {
        return new LeadResource(
            $leads->assignLead($request->user(), $lead, $request->validated('assigned_user_id')),
        );
    }

    public function stage(MoveLeadStageRequest $request, Lead $lead, LeadService $leads): LeadResource
    {
        return new LeadResource(
            $leads->moveLeadToStage($request->user(), $lead, (int) $request->validated('pipeline_stage_id')),
        );
    }

    public function convert(ConvertLeadRequest $request, Lead $lead, LeadConversionService $conversions): LeadResource
    {
        return new LeadResource($conversions->convertLead($request->user(), $lead));
    }
}
