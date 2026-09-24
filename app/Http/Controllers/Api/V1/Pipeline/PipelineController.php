<?php

namespace App\Http\Controllers\Api\V1\Pipeline;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Pipeline\UpdatePipelineStageRequest;
use App\Http\Resources\Api\V1\PipelineResource;
use App\Http\Resources\Api\V1\PipelineStageResource;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Services\Crm\PipelineService;

class PipelineController extends Controller
{
    public function show(PipelineService $pipelines): PipelineResource
    {
        $this->authorize('viewAny', Pipeline::class);

        return new PipelineResource($pipelines->defaultPipeline());
    }

    public function updateStage(
        UpdatePipelineStageRequest $request,
        PipelineStage $stage,
        PipelineService $pipelines,
    ): PipelineStageResource {
        return new PipelineStageResource($pipelines->updateStage($stage, $request->validated()));
    }

    public function activateStage(PipelineStage $stage, PipelineService $pipelines): PipelineStageResource
    {
        $this->authorize('activate', $stage);

        return new PipelineStageResource($pipelines->activate($stage));
    }

    public function deactivateStage(PipelineStage $stage, PipelineService $pipelines): PipelineStageResource
    {
        $this->authorize('deactivate', $stage);

        return new PipelineStageResource($pipelines->deactivate($stage));
    }
}
