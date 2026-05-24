<?php

namespace App\Console\Commands;

use App\Models\Service;
use App\Models\ServiceMetric;
use App\Services\Pterodactyl\PterodactylService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Muestrea CPU, RAM, disco y red de todos los game servers activos (Pterodactyl)
 * y persiste los resultados en service_metrics (usado por los gráficos de historial).
 *
 * Se ejecuta cada 5 minutos desde Console\Kernel.
 * Purga automáticamente registros con más de 48 h de antigüedad.
 */
class CollectServiceMetrics extends Command
{
    protected $signature = 'services:collect-metrics
                            {--dry-run : Mostrar resultados sin persistir}
                            {--service= : Solo muestrear un servicio específico (UUID)}';

    protected $description = 'Muestrea y persiste métricas de recursos de todos los game servers activos';

    public function handle(PterodactylService $pterodactyl): int
    {
        $dryRun   = $this->option('dry-run');
        $uuidOnly = $this->option('service');

        // Purgar historial antiguo (>48 h)
        if (! $dryRun) {
            ServiceMetric::where('sampled_at', '<', now()->subHours(48))->delete();
        }

        $query = Service::query()
            ->where('status', 'active')
            ->whereNotNull('pterodactyl_server_id')
            ->with('plan');

        if ($uuidOnly) {
            $query->where('uuid', $uuidOnly);
        }

        $services = $query->get(['id', 'uuid', 'pterodactyl_server_id', 'connection_details', 'plan_id']);

        if ($services->isEmpty()) {
            $this->info('No hay game servers activos con ID de Pterodactyl.');
            return self::SUCCESS;
        }

        $sampled = 0;
        $now     = now();

        foreach ($services as $service) {
            $identifier = $service->connection_details['identifier'] ?? null;

            if (! $identifier) {
                continue;
            }

            try {
                $resources = $pterodactyl->getServerResources($identifier);

                $cpuPct      = round((float) ($resources['resources']['cpu_absolute']     ?? 0), 2);
                $memBytes    = (int) ($resources['resources']['memory_bytes']              ?? 0);
                $diskBytes   = (int) ($resources['resources']['disk_bytes']                ?? 0);
                $networkRx   = (int) ($resources['resources']['network_rx_bytes']          ?? 0);
                $networkTx   = (int) ($resources['resources']['network_tx_bytes']          ?? 0);
                $state       = $resources['current_state']                                 ?? 'unknown';

                $memLimit  = ($service->plan?->pterodactyl_limits['memory'] ?? 0) * 1024 * 1024;
                $diskLimit = ($service->plan?->pterodactyl_limits['disk']   ?? 0) * 1024 * 1024;

                if ($this->option('verbose')) {
                    $this->line(sprintf(
                        '  [%s] cpu=%.1f%% mem=%dMB/%dMB disk=%dMB/%dMB state=%s',
                        $service->uuid,
                        $cpuPct,
                        $memBytes   / 1024 / 1024,
                        $memLimit   / 1024 / 1024,
                        $diskBytes  / 1024 / 1024,
                        $diskLimit  / 1024 / 1024,
                        $state,
                    ));
                }

                if (! $dryRun) {
                    ServiceMetric::create([
                        'service_id'          => $service->id,
                        'cpu_percent'         => $cpuPct,
                        'memory_bytes'        => $memBytes,
                        'memory_limit_bytes'  => $memLimit,
                        'disk_bytes'          => $diskBytes,
                        'disk_limit_bytes'    => $diskLimit,
                        'network_rx_bytes'    => $networkRx,
                        'network_tx_bytes'    => $networkTx,
                        'state'               => $state,
                        'sampled_at'          => $now,
                    ]);
                }

                $sampled++;
            } catch (\Throwable $e) {
                Log::warning('collect-metrics: error al muestrear servicio', [
                    'service_id' => $service->id,
                    'identifier' => $identifier,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        $this->info("collect-metrics: {$sampled} servicio(s) muestreados." . ($dryRun ? ' [dry-run]' : ''));

        return self::SUCCESS;
    }
}
