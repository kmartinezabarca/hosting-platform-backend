<?php

namespace App\Domains\Platform\Console\Commands;

use App\Domains\Platform\Services\ServiceStatusSyncService;
use Illuminate\Console\Command;

/**
 * Sincroniza el `live_status` y `live_metrics` de todos los servicios activos contra
 * el proveedor real (Pterodactyl / Coolify).
 *
 * Se ejecuta cada minuto desde Console\Kernel. El usuario ve el estado real en su
 * dashboard sin tener que esperar al próximo refresh manual.
 */
class SyncServiceStatus extends Command
{
    protected $signature = 'services:sync-status
                            {--service= : Solo sincronizar un servicio específico (UUID)}';

    protected $description = 'Sincroniza live_status / live_metrics de todos los servicios activos.';

    public function handle(ServiceStatusSyncService $sync): int
    {
        $only = $this->option('service');

        if ($only) {
            $service = \App\Domains\Platform\Models\Service::where('uuid', $only)->with('plan.category')->first();
            if (! $service) {
                $this->error("Servicio {$only} no encontrado.");
                return self::FAILURE;
            }
            $sync->syncOne($service);
            $this->info("OK: {$only} → {$service->live_status}");
            return self::SUCCESS;
        }

        [$synced, $failed] = $sync->syncAll();

        $this->info("sync-status: {$synced} sincronizado(s), {$failed} fallido(s).");

        return self::SUCCESS;
    }
}
