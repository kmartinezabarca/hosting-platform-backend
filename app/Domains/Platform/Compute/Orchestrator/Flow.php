<?php

namespace App\Domains\Platform\Compute\Orchestrator;

use App\Domains\Platform\Compute\Models\Orchestration;

abstract class Flow
{
    /** Identificador persistido en orchestrations.flow. */
    abstract public static function key(): string;

    /** @return class-string<Step>[] en orden de ejecución */
    abstract public function steps(): array;

    /** Cola donde corre la saga. */
    public function queue(): string
    {
        return config('compute.queues.provisioning', 'provisioning');
    }

    public function onSuccess(Orchestration $orchestration): void
    {
    }

    public function onFailure(Orchestration $orchestration, \Throwable $e): void
    {
    }
}
