<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_invoices', function (Blueprint $table) {
            // ID interno de Facturama — necesario para descargar/cancelar el CFDI
            $table->string('facturama_id')->nullable()->after('uuid');

            // Folio secuencial visible en la factura (ej. F-0042)
            $table->unsignedInteger('folio')->nullable()->after('facturama_id');

            // FK a la factura interna del sistema
            $table->unsignedBigInteger('invoice_id')->nullable()->after('folio');
            $table->foreign('invoice_id')->references('id')->on('invoices')->nullOnDelete();

            $table->index('facturama_id');
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('service_invoices', function (Blueprint $table) {
            $table->dropForeign(['invoice_id']);
            $table->dropIndex(['facturama_id']);
            $table->dropIndex(['invoice_id']);
            $table->dropColumn(['facturama_id', 'folio', 'invoice_id']);
        });
    }
};
