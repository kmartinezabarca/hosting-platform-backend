<?php

namespace App\Domains\Platform\Compute\Orchestrator\Steps;

use App\Domains\Platform\Compute\Models\Orchestration;
use App\Domains\Platform\Compute\Orchestrator\Step;
use App\Domains\Platform\Compute\Orchestrator\StepResult;
use App\Domains\Platform\Compute\Providers\Contracts\DatabaseDriver;
use RuntimeException;

/**
 * Espera a que el data store esté running (polling sin bloquear el worker) y
 * persiste su conexión interna cifrada. Esa conexión es la que el resolutor de
 * bindings inyecta como DB_HOST/DB_PASSWORD… en las apps del mismo ambiente.
 */
class AwaitDatabaseReady implements Step
{
    public function __construct(private readonly DatabaseDriver $driver)
    {
    }

    public function execute(Orchestration $orchestration): StepResult
    {
        $resource   = $orchestration->resource;
        $externalId = $resource->providerRef('coolify')->external_id;

        $status = $this->driver->getDatabase($externalId)['status'];

        if ($status === 'failed') {
            throw new RuntimeException('El proveedor reportó la base de datos como fallida.');
        }

        if ($status !== 'running') {
            return StepResult::pending();
        }

        // Idempotente: solo lee la conexión la primera vez que está lista.
        if ($resource->connection() === null) {
            $resource->update(['connection_encrypted' => $this->driver->connectionInfo($externalId)]);
        }

        return StepResult::completed();
    }
}
