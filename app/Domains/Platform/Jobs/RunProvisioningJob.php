<?php

namespace App\Domains\Platform\Jobs;

use App\Domains\Platform\Models\ProvisioningJob;
use App\Domains\Platform\Services\ProvisioningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Ejecuta el aprovisionamiento de un servicio en segundo plano.
 *
 * La contratación encola este job y responde de inmediato; un worker ejecuta
 * el aprovisionamiento (Coolify / Pterodactyl) sin bloquear el request HTTP del
 * checkout. En tests (QUEUE_CONNECTION=sync) corre inline, preservando el
 * comportamiento síncrono que esperan las pruebas.
 *
 * Toda la idempotencia, reintentos y backoff viven en
 * {@see ProvisioningService::runJob()} — este job es solo el disparador async.
 * Por eso `tries = 1`: runJob captura sus propias excepciones y reprograma la
 * fila provisioning_jobs; el comando `provisioning:process-pending` es la red
 * de seguridad si el worker no está corriendo.
 */
class RunProvisioningJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $provisioningJobId) {}

    public function handle(ProvisioningService $provisioning): void
    {
        $job = ProvisioningJob::with('service')->find($this->provisioningJobId);

        // Job inexistente o ya completado por otra vía (cron de respaldo / reintento).
        if (! $job || $job->status === ProvisioningJob::STATUS_SUCCEEDED) {
            return;
        }

        $provisioning->runJob($job);
    }
}
