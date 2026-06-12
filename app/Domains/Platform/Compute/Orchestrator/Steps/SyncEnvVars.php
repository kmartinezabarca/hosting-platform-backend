<?php

namespace App\Domains\Platform\Compute\Orchestrator\Steps;

use App\Domains\Platform\Compute\Models\Orchestration;
use App\Domains\Platform\Compute\Orchestrator\Step;
use App\Domains\Platform\Compute\Orchestrator\StepResult;
use App\Domains\Platform\Compute\Providers\Contracts\AppRuntimeDriver;

/**
 * Empuja las env vars del ambiente al runtime. Los valores se desencriptan
 * solo aquí (cast encrypted) y viajan directo al proveedor — nunca pasan
 * por respuestas de API ni logs.
 */
class SyncEnvVars implements Step
{
    public function __construct(private readonly AppRuntimeDriver $driver)
    {
    }

    public function execute(Orchestration $orchestration): StepResult
    {
        $resource = $orchestration->resource;
        $appId    = $resource->providerRef('coolify')?->external_id;

        $vars = $resource->environment->envVars
            ->mapWithKeys(fn ($var) => [$var->key => (string) $var->value_encrypted])
            ->all();

        if ($vars !== []) {
            $this->driver->syncEnvVars($appId, $vars);
        }

        return StepResult::completed();
    }
}
