<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices para las búsquedas por referencia de pago:
 *   - payment_reference: lookup de reembolsos/auditoría por PaymentIntent.
 *   - provider_invoice_id: idempotencia de receipts de renovación
 *     (RenewalAccountingService dedup por invoice de Stripe).
 * Aditivo y seguro.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->index('payment_reference');
            $table->index('provider_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropIndex(['payment_reference']);
            $table->dropIndex(['provider_invoice_id']);
        });
    }
};
