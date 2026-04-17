<?php

namespace App\Console\Commands;

use App\Models\ServiceInvoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Procesa los registros de service_invoices cuyo plazo de 72 horas ya venció
 * y cuyo cfdi_status es 'scheduled' (Público en General).
 *
 * Programar en app/Console/Kernel.php:
 *   $schedule->command('cfdi:stamp-pending')->hourly();
 */
class StampPendingCfdis extends Command
{
    protected $signature   = 'cfdi:stamp-pending {--dry-run : Mostrar registros sin timbrar}';
    protected $description = 'Timbra como Público en General las facturas cuyo plazo de 72 h ya venció';

    public function handle(): int
    {
        $due = ServiceInvoice::dueForStamping()
            ->with('service.user')
            ->get();

        if ($due->isEmpty()) {
            $this->info('No hay facturas pendientes de timbrado.');
            return self::SUCCESS;
        }

        $this->info("Facturas por timbrar: {$due->count()}");

        if ($this->option('dry-run')) {
            $this->table(
                ['ID', 'Servicio', 'RFC', 'Programado para'],
                $due->map(fn($si) => [
                    $si->id,
                    $si->service_id,
                    $si->rfc,
                    $si->stamp_scheduled_at?->toDateTimeString(),
                ])->toArray()
            );
            return self::SUCCESS;
        }

        $stamped = 0;
        $failed  = 0;

        foreach ($due as $si) {
            try {
                // ──────────────────────────────────────────────────────────
                // INTEGRACIÓN CON PAC (Facturama, SW Sapien, etc.)
                // Aquí iría la llamada al servicio de timbrado real.
                //
                // Ejemplo cuando se integre el PAC:
                // $cfdi = app(CfdiService::class)->stamp($si);
                // $si->update([
                //     'cfdi_status'   => ServiceInvoice::CFDI_STAMPED,
                //     'cfdi_uuid'     => $cfdi->uuid,
                //     'cfdi_xml'      => $cfdi->xml,
                //     'cfdi_pdf_path' => $cfdi->pdfPath,
                //     'stamped_at'    => now(),
                // ]);
                // ──────────────────────────────────────────────────────────

                // Mientras no haya PAC: mover a pending_stamp para proceso manual
                $si->update([
                    'cfdi_status' => ServiceInvoice::CFDI_PENDING_STAMP,
                    'cfdi_error'  => null,
                ]);

                $stamped++;

                Log::info('CFDI listo para timbrado (Público en General)', [
                    'service_invoice_id' => $si->id,
                    'service_id'         => $si->service_id,
                    'rfc'                => $si->rfc,
                    'user_id'            => $si->service?->user_id,
                ]);
            } catch (\Throwable $e) {
                $failed++;
                $si->update([
                    'cfdi_status' => ServiceInvoice::CFDI_FAILED,
                    'cfdi_error'  => $e->getMessage(),
                ]);
                Log::error('Error al procesar CFDI pendiente', [
                    'service_invoice_id' => $si->id,
                    'error'              => $e->getMessage(),
                ]);
            }
        }

        $this->info("Procesados: {$stamped} exitosos, {$failed} con error.");
        return self::SUCCESS;
    }
}
