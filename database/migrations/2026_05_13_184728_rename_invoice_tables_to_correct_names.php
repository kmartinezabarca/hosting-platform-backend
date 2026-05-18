<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Rename tables so nomenclature matches domain meaning:
 *   invoices         → receipts   (comprobante de pago interno)
 *   service_invoices → invoices   (factura CFDI SAT)
 *
 * FK columns (invoice_id, etc.) keep their names — only tables change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('invoices', 'receipts');
        Schema::rename('service_invoices', 'invoices');
    }

    public function down(): void
    {
        Schema::rename('invoices', 'service_invoices');
        Schema::rename('receipts', 'invoices');
    }
};
