<?php

namespace App\Services\Crm;

use App\Models\Pipeline;
use App\Models\PipelineStage;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PipelineService
{
    public function defaultPipeline(): Pipeline
    {
        $pipeline = Pipeline::query()
            ->default()
            ->with('stages')
            ->first();

        if ($pipeline === null) {
            throw new NotFoundHttpException('The default pipeline is not configured.');
        }

        return $pipeline;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateStage(PipelineStage $stage, array $attributes): PipelineStage
    {
        return DB::transaction(function () use ($stage, $attributes) {
            if (array_key_exists('position', $attributes)) {
                $this->moveToPosition($stage, (int) $attributes['position']);
                unset($attributes['position']);
            }

            if ($attributes !== []) {
                $stage->update($attributes);
            }

            return $stage->refresh()->load('pipeline');
        });
    }

    public function activate(PipelineStage $stage): PipelineStage
    {
        $stage->update(['is_active' => true]);

        return $stage->refresh();
    }

    public function deactivate(PipelineStage $stage): PipelineStage
    {
        $stage->update(['is_active' => false]);

        return $stage->refresh();
    }

    private function moveToPosition(PipelineStage $stage, int $position): void
    {
        $max = $stage->pipeline()->firstOrFail()->stages()->count();
        $position = max(1, min($position, $max));
        $current = (int) $stage->position;

        if ($current === $position) {
            return;
        }

        if ($position < $current) {
            PipelineStage::query()
                ->where('pipeline_id', $stage->pipeline_id)
                ->whereBetween('position', [$position, $current - 1])
                ->increment('position');
        } else {
            PipelineStage::query()
                ->where('pipeline_id', $stage->pipeline_id)
                ->whereBetween('position', [$current + 1, $position])
                ->decrement('position');
        }

        $stage->update(['position' => $position]);
    }
}
