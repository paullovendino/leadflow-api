<?php

namespace App\Http\Controllers\Api\V1\Leads;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Leads\StoreLeadNoteRequest;
use App\Http\Resources\Api\V1\NoteResource;
use App\Models\Lead;
use App\Services\Crm\NoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LeadNoteController extends Controller
{
    public function index(Lead $lead, NoteService $notes): AnonymousResourceCollection
    {
        $this->authorize('view', $lead);

        return NoteResource::collection($notes->listForLead($lead));
    }

    public function store(StoreLeadNoteRequest $request, Lead $lead, NoteService $notes): JsonResponse
    {
        return (new NoteResource($notes->createForLead($request->user(), $lead, $request->validated('body'))))
            ->response()
            ->setStatusCode(201);
    }
}
