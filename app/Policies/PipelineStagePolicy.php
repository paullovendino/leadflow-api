<?php

namespace App\Policies;

use App\Models\PipelineStage;
use App\Models\User;

class PipelineStagePolicy
{
    public function update(User $actor, PipelineStage $stage): bool
    {
        return $actor->isManager();
    }

    public function activate(User $actor, PipelineStage $stage): bool
    {
        return $actor->isManager();
    }

    public function deactivate(User $actor, PipelineStage $stage): bool
    {
        return $actor->isManager();
    }
}
