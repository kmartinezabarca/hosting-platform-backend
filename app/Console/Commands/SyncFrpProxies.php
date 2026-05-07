<?php

namespace App\Console\Commands;

use App\Models\Service;
use App\Services\FrpService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncFrpProxies extends Command
{
    protected $signature   = 'frp:sync-proxies {--dry-run : Solo mostrar, no ejecutar}';
    protected $description = 'Agrega proxies frp faltantes para todos los game servers activos';

    public function __construct(private readonly FrpService $frp)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $services = Service::where('status', 'active')
            ->whereNotNull('pterodactyl_server_id')
            ->get();

        $this->info("Encontrados {$services->count()} game servers activos.");

        $added   = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ($services as $service) {
            $conn = $service->connection_details ?? [];
            $port = $conn['server_port'] ?? null;

            if (!$port) {
                $this->warn("  [SKIP] {$service->name} — sin puerto en connection_details");
                $skipped++;
                continue;
            }

            // Ya tiene frp registrado
            if (!empty($conn['frp_port'])) {
                $this->line("  [OK]   {$service->name} — puerto {$port} ya registrado");
                $skipped++;
                continue;
            }

            $this->line("  [ADD]  {$service->name} — puerto {$port}");

            if ($this->option('dry-run')) {
                $added++;
                continue;
            }

            try {
                $ok = $this->frp->addTcpProxy((int) $port, $service->name);

                if ($ok) {
                    $service->update([
                        'connection_details' => array_merge($conn, ['frp_port' => $port]),
                    ]);
                    $added++;
                } else {
                    $this->error("  [FAIL] {$service->name} — frp falló");
                    $failed++;
                }
            } catch (\Throwable $e) {
                $this->error("  [ERR]  {$service->name} — {$e->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Resultado: {$added} agregados · {$skipped} omitidos · {$failed} fallidos");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
