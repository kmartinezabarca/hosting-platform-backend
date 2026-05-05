<?php

namespace App\Console\Commands;

use App\Jobs\CheckAndFixJavaCompatibilityJob;
use App\Models\Service;
use App\Services\Pterodactyl\PterodactylService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Comando de monitoreo automático de salud de servidores de juego.
 *
 * Se ejecuta cada 5 minutos desde el Kernel de consola.
 * Por cada servidor Pterodactyl activo:
 *   1. Consulta el estado actual (running / offline / starting / installing).
 *   2. Si está offline → encola CheckAndFixJavaCompatibilityJob para auto-sanación.
 *   3. Si está running o starting → no hace nada.
 *
 * El job encolado se encarga de:
 *   - Leer los logs del servidor.
 *   - Detectar errores de incompatibilidad de Java.
 *   - Corregir la imagen Docker automáticamente.
 *   - Reiniciar el servidor.
 *   - Notificar al dueño y/o admins según el resultado.
 */
class MonitorGameServerHealth extends Command
{
    protected $signature = 'game-servers:monitor-health
                            {--dry-run : Mostrar servidores offline sin encolar jobs}
                            {--service= : Monitorear solo un servicio específico (UUID)}';

    protected $description = 'Monitorea la salud de todos los servidores de juego activos y auto-corrige errores de Java';

    public function handle(PterodactylService $pterodactyl): int
    {
        $dryRun    = $this->option('dry-run');
        $uuidOnly  = $this->option('service');

        $this->info('MonitorGameServerHealth: iniciando escaneo…');

        // ── Obtener servicios activos gestionados por Pterodactyl ────────────────
        $query = Service::query()
            ->whereIn('status', ['active', 'pending'])
            ->whereNotNull('pterodactyl_server_id')
            ->whereNotNull('connection_details');

        if ($uuidOnly) {
            $query->where('uuid', $uuidOnly);
        }

        $services = $query->get();

        if ($services->isEmpty()) {
            $this->info('No hay servidores activos que monitorear.');
            return self::SUCCESS;
        }

        $this->info("Escaneando {$services->count()} servidor(es)…");

        $stats = [
            'running'    => 0,
            'starting'   => 0,
            'installing' => 0,
            'offline'    => 0,
            'queued'     => 0,
            'error'      => 0,
            'skipped'    => 0,
        ];

        foreach ($services as $service) {
            $identifier = $service->connection_details['identifier'] ?? null;

            if (! $identifier) {
                $this->warn("  [{$service->uuid}] Sin identifier, saltando.");
                $stats['skipped']++;
                continue;
            }

            // ── Consultar estado ─────────────────────────────────────────────────
            try {
                $resources = $pterodactyl->getServerResources($identifier);
                $state     = $resources['current_state'] ?? 'offline';
            } catch (\Throwable $e) {
                $this->warn("  [{$service->uuid}] No se pudo consultar el estado: {$e->getMessage()}");
                Log::warning('MonitorGameServerHealth: error al consultar recursos', [
                    'service_id' => $service->id,
                    'error'      => $e->getMessage(),
                ]);
                $stats['error']++;
                continue;
            }

            // ── Evaluar y actuar ─────────────────────────────────────────────────
            switch ($state) {
                case 'running':
                    $this->line("  [{$service->uuid}] ✓ running");
                    $stats['running']++;
                    break;

                case 'starting':
                    $this->line("  [{$service->uuid}] ⏳ starting (esperando…)");
                    $stats['starting']++;
                    break;

                case 'installing':
                    $this->line("  [{$service->uuid}] 🔧 installing (esperando…)");
                    $stats['installing']++;
                    break;

                case 'offline':
                default:
                    $this->warn("  [{$service->uuid}] ✗ OFFLINE — {$service->name}");
                    $stats['offline']++;

                    if ($dryRun) {
                        $this->line("     [dry-run] No se encola el job.");
                    } else {
                        // Solo encolar si no hay ya un job pendiente para este servicio.
                        // (El job es idempotente, pero evitamos duplicados innecesarios.)
                        CheckAndFixJavaCompatibilityJob::dispatch($service);

                        Log::info('MonitorGameServerHealth: encolado CheckAndFixJavaCompatibilityJob', [
                            'service_id' => $service->id,
                            'identifier' => $identifier,
                        ]);

                        $stats['queued']++;
                        $this->line("     → Job encolado para auto-sanación.");
                    }
                    break;
            }
        }

        // ── Resumen ──────────────────────────────────────────────────────────────
        $this->newLine();
        $this->info('Resumen del escaneo:');
        $this->table(
            ['Estado',     'Count'],
            [
                ['running',    $stats['running']],
                ['starting',   $stats['starting']],
                ['installing', $stats['installing']],
                ['offline',    $stats['offline']],
                ['jobs encolados', $stats['queued']],
                ['errores API',    $stats['error']],
                ['sin identifier', $stats['skipped']],
            ]
        );

        Log::info('MonitorGameServerHealth: escaneo completo', $stats);

        return self::SUCCESS;
    }
}
