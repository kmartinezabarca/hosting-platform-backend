<?php

namespace App\Console\Commands;

use App\Models\ProvisioningJob;
use App\Services\ProvisioningService;
use Illuminate\Console\Command;

/**
 * Reintenta los jobs de aprovisionamiento pendientes o fallidos (con intentos
 * restantes) cuyo backoff ya venció. Programado en app/Console/Kernel.php.
 */
class ProcessPendingProvisioning extends Command
{
    protected $signature = 'provisioning:process-pending {--dry-run : Listar sin ejecutar}';

    protected $description = 'Reintenta aprovisionamientos pendientes/fallidos (Pterodactyl/Coolify) con reintentos y backoff.';

    public function handle(ProvisioningService $provisioning): int
    {
        $jobs = ProvisioningJob::retryable()
            ->with('service')
            ->orderBy('available_at')
            ->limit(50)
            ->get();

        if ($jobs->isEmpty()) {
            $this->info('No hay aprovisionamientos pendientes.');
            return self::SUCCESS;
        }

        $ok = 0;
        $fail = 0;

        foreach ($jobs as $job) {
            $this->line(" → Servicio #{$job->service_id} ({$job->provider}) intento " . ($job->attempts + 1) . "/{$job->max_attempts}");

            if ($this->option('dry-run')) {
                continue;
            }

            $provisioning->runJob($job) ? $ok++ : $fail++;
        }

        $this->info(($this->option('dry-run') ? '[DRY-RUN] ' : '') . "OK: {$ok} · Fallidos/reintento: {$fail}");

        return self::SUCCESS;
    }
}
