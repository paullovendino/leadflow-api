<?php

namespace App\Http\Controllers\Api\V1\Leads;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ActivityResource;
use App\Models\Lead;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LeadActivityController extends Controller
{
    public function index(Lead $lead): AnonymousResourceCollection
    {
        $this->authorize('view', $lead);

        return ActivityResource::collection($lead->activities()->with('user')->get());
    }
}
