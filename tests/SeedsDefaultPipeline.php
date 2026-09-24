<?php

namespace Tests;

use App\Models\Pipeline;
use App\Models\PipelineStage;
use Database\Seeders\DefaultPipelineSeeder;

trait SeedsDefaultPipeline
{
    protected function seedDefaultPipeline(): Pipeline
    {
        $this->seed(DefaultPipelineSeeder::class);

        return Pipeline::query()->where('is_default', true)->with('stages')->firstOrFail();
    }

    protected function stageBySlug(string $slug): PipelineStage
    {
        return PipelineStage::query()->where('slug', $slug)->firstOrFail();
    }
}
