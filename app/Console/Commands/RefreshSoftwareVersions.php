<?php

namespace App\Console\Commands;

use App\Services\SoftwareVersionService;
use Illuminate\Console\Command;

/**
 * Artisan command: software:refresh-versions
 *
 * Actualiza la caché de versiones de software de servidor de Minecraft
 * consultando las APIs externas (PaperMC, Purpur, Fabric, Mojang).
 *
 * Uso:
 *   php artisan software:refresh-versions              # refresca todos los identificadores conocidos
 *   php artisan software:refresh-versions --id=paper  # refresca solo uno
 *   php artisan software:refresh-versions --dry-run   # muestra qué haría sin actualizar
 *
 * Se programa en Kernel.php para ejecutarse cada noche a las 03:00.
 */
class RefreshSoftwareVersions extends Command
{
    protected $signature = 'software:refresh-versions
                            {--id=    : Identificador concreto a refrescar (paper, purpur, fabric, vanilla...)}
                            {--dry-run : Muestra los identificadores sin actualizar la caché}';

    protected $description = 'Actualiza la caché de versiones de software de Minecraft desde APIs externas';

    public function handle(SoftwareVersionService $service): int
    {
        if ($this->option('dry-run')) {
            $this->warn('DRY RUN — no se actualizará ninguna caché.');
            $this->newLine();
        }

        $ids = $this->option('id')
            ? [strtolower(trim($this->option('id')))]
            : SoftwareVersionService::knownIdentifiers();

        $this->info('Identificadores a procesar: ' . implode(', ', $ids));
        $this->newLine();

        $success = 0;
        $failed  = 0;

        foreach ($ids as $id) {
            if ($this->option('dry-run')) {
                $this->line("  [DRY] {$id}  →  clave de caché: " . SoftwareVersionService::cacheKey($id));
                continue;
            }

            try {
                $result = $service->refreshVersions($id);
                $count  = count($result['versions']);
                $this->line("  <fg=green>✓</> {$id}  →  {$count} versiones cargadas.");
                $success++;
            } catch (\Throwable $e) {
                $this->error("  ✗ {$id}  →  {$e->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();

        if (!$this->option('dry-run')) {
            $this->info("Resultado: {$success} exitosos, {$failed} con error.");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
