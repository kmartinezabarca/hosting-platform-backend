<?php

namespace App\Domains\Platform\Compute\Orchestrator\Steps;

use App\Domains\Platform\Compute\Enums\ResourceStatus;
use App\Domains\Platform\Compute\Models\Orchestration;
use App\Domains\Platform\Compute\Orchestrator\Step;
use App\Domains\Platform\Compute\Orchestrator\StepResult;

class MarkResourceRunning implements Step
{
    public function execute(Orchestration $orchestration): StepResult
    {
        $orchestration->resource->update(['status' => ResourceStatus::Running]);

        return StepResult::completed();
    }
}
