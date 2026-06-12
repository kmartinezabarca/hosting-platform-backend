<?php

namespace App\Domains\Platform\Compute\Orchestrator\Flows;

use App\Domains\Platform\Compute\Enums\ResourceStatus;
use App\Domains\Platform\Compute\Models\Orchestration;
use App\Domains\Platform\Compute\Orchestrator\Flow;
use App\Domains\Platform\Compute\Orchestrator\Steps\AwaitDatabaseReady;
use App\Domains\Platform\Compute\Orchestrator\Steps\CreateDatabase;
use App\Domains\Platform\Compute\Orchestrator\Steps\MarkResourceRunning;

/**
 * Provisión de un data store administrado (MySQL/Postgres/Redis): crear en el
 * proveedor → esperar a que arranque y leer credenciales → running. Sin build
 * ni dominio (no es una app); las apps lo consumen vía detection bindings.
 */
class ProvisionDatabaseFlow extends Flow
{
    public static function key(): string
    {
        return 'provision_database';
    }

    public function steps(): array
    {
        return [
            CreateDatabase::class,
            AwaitDatabaseReady::class,
            MarkResourceRunning::class,
        ];
    }

    public function onFailure(Orchestration $orchestration, \Throwable $e): void
    {
        $orchestration->resource?->update(['status' => ResourceStatus::Failed]);
    }
}
