<?php

namespace App\Console\Commands;

use App\Models\Service;
use App\Services\HostingHealthService;
use Illuminate\Console\Command;

/**
 * Muestrea disponibilidad (uptime) y latencia REAL de los sitios de hosting
 * (Coolify) activos. Programado cada 5 min en app/Console/Kernel.php.
 *
 * Es la fuente de métricas de hosting: Coolify no expone CPU/RAM, pero un GET
 * periódico al dominio sí da datos reales de uptime y latencia.
 */
class CheckHostingHealth extends Command
{
    protected $signature = 'hosting:check-health {--dry-run : Listar sin medir}';

    protected $description = 'Mide uptime y latencia de los sitios de hosting activos (health check HTTP real).';

    public function handle(HostingHealthService $health): int
    {
        $services = Service::query()
            ->where('status', 'active')
            ->whereHas('plan', fn ($q) => $q->where('provisioner', 'coolify'))
            ->with('plan')
            ->get();

        if ($services->isEmpty()) {
            $this->info('No hay servicios de hosting activos.');
            return self::SUCCESS;
        }

        $checked = 0;
        $down = 0;

        foreach ($services as $service) {
            $url = $health->urlFor($service);
            if (! $url) {
                continue;
            }

            $this->line(" → {$service->name} ({$url})");

            if ($this->option('dry-run')) {
                continue;
            }

            $result = $health->check($service);
            if ($result) {
                $checked++;
                if (! $result->ok) {
                    $down++;
                }
            }
        }

        // Retención: conservar 7 días de historial.
        $pruned = $this->option('dry-run') ? 0 : $health->prune(7);

        $this->info(($this->option('dry-run') ? '[DRY-RUN] ' : '') . "Medidos: {$checked} · Caídos: {$down} · Purgados: {$pruned}");

        return self::SUCCESS;
    }
}
