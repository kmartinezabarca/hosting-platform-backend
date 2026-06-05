<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renumera los RECIBOS: el número interno dejaba ver "INV-" (pinta de
 * factura) cuando en realidad es un recibo de pago. Pasa el prefijo
 * INV- → REC- en los registros históricos para que sea claro y
 * consistente con la nueva generación (config('app.receipt_prefix')).
 *
 * La factura fiscal (CFDI) conserva su propia serie+folio aparte.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('receipts')
            ->where('invoice_number', 'like', 'INV-%')
            ->update([
                'invoice_number' => DB::raw("CONCAT('REC-', SUBSTRING(invoice_number, 5))"),
            ]);
    }

    public function down(): void
    {
        DB::table('receipts')
            ->where('invoice_number', 'like', 'REC-%')
            ->update([
                'invoice_number' => DB::raw("CONCAT('INV-', SUBSTRING(invoice_number, 5))"),
            ]);
    }
};
