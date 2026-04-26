<?php

namespace App\Console\Commands;

use App\Models\ServiceInvoice;
use App\Services\Factura\CfdiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Timbra como "Público en General" las facturas cuyo plazo de 72 h ya venció
 * y timbra también cualquier CFDI en estado pending_stamp.
 *
 * Programado en app/Console/Kernel.php → hourly.
 */
class StampPendingCfdis extends Command
{
    protected $signature   = 'cfdi:stamp-pending {--dry-run : Mostrar registros sin ejecutar}';
    protected $description = 'Timbra los CFDIs pendientes o cuyo plazo de 72 h ya venció';

    public function __construct(private readonly CfdiService $cfdi)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // ── 1) Scheduled (Público en General cuyo plazo ya venció) ──────────
        $scheduled = ServiceInvoice::dueForStamping()->with('service.user')->get();

        // ── 2) pending_stamp (datos reales aún no timbrados) ────────────────
        $pending = ServiceInvoice::where('cfdi_status', ServiceInvoice::CFDI_PENDING_STAMP)
            ->with('service.user')
            ->get();

        $all = $scheduled->merge($pending)->unique('id');

        if ($all->isEmpty()) {
            $this->info('No hay CFDIs pendientes de timbrado.');
            return self::SUCCESS;
        }

        $this->info("CFDIs por timbrar: {$all->count()} ({$scheduled->count()} programados, {$pending->count()} pendientes)");

        if ($this->option('dry-run')) {
            $this->table(
                ['ID', 'Estado', 'RFC', 'Scheduled at', 'Servicio'],
                $all->map(fn($si) => [
                    $si->id,
                    $si->cfdi_status,
                    $si->rfc,
                    $si->stamp_scheduled_at?->toDateTimeString() ?? '—',
                    $si->service_id,
                ])->toArray()
            );
            return self::SUCCESS;
        }

        $stamped = 0;
        $failed  = 0;

        foreach ($all as $si) {
            try {
                $this->cfdi->stamp($si);
                $stamped++;
                $this->line("  ✓ ServiceInvoice #{$si->id} timbrado (UUID: {$si->fresh()->cfdi_uuid})");
            } catch (\Throwable $e) {
                $failed++;
                $this->error("  ✗ ServiceInvoice #{$si->id}: {$e->getMessage()}");
            }
        }

        $this->info("Resultado: {$stamped} timbrados, {$failed} con error.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
