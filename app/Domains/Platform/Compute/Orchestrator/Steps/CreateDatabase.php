<?php

namespace App\Domains\Platform\Compute\Orchestrator\Steps;

use App\Domains\Platform\Compute\Enums\ResourceKind;
use App\Domains\Platform\Compute\Enums\ResourceStatus;
use App\Domains\Platform\Compute\Models\Orchestration;
use App\Domains\Platform\Compute\Orchestrator\Step;
use App\Domains\Platform\Compute\Orchestrator\StepResult;
use App\Domains\Platform\Compute\Providers\Contracts\DatabaseDriver;

/**
 * Crea el data store en el proveedor (idempotente: si ya hay provider ref, no
 * re-crea) y lo arranca. El engine sale del kind/spec; las credenciales las
 * genera el proveedor y se leen en AwaitDatabaseReady.
 */
class CreateDatabase implements Step
{
    public function __construct(private readonly DatabaseDriver $driver)
    {
    }

    public function execute(Orchestration $orchestration): StepResult
    {
        $resource = $orchestration->resource;

        if ($resource->providerRef('coolify')) {
            return StepResult::completed();
        }

        $externalId = $this->driver->createDatabase($resource, [
            'engine'  => $this->engine($resource),
            'name'    => data_get($resource->spec, 'database', $resource->name),
            'version' => data_get($resource->spec, 'version'),
        ]);

        $resource->providerRefs()->create([
            'provider'    => 'coolify',
            'external_id' => $externalId,
        ]);

        $this->driver->startDatabase($externalId);

        $resource->update(['status' => ResourceStatus::Provisioning]);

        return StepResult::completed();
    }

    private function engine($resource): string
    {
        if ($resource->kind === ResourceKind::Redis) {
            return 'redis';
        }

        return (string) data_get($resource->spec, 'engine', 'mysql');
    }
}
