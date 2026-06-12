<?php

namespace App\Domains\Platform\Compute\Orchestrator;

use App\Domains\Platform\Compute\Models\Orchestration;

/**
 * Paso de una saga. Contrato: IDEMPOTENTE — el runner puede re-ejecutar un
 * paso tras un crash del worker, así que todo paso debe verificar si su
 * efecto ya existe antes de crearlo (ej. buscar el provider ref antes de
 * crear la app en Coolify).
 */
interface Step
{
    public function execute(Orchestration $orchestration): StepResult;
}
