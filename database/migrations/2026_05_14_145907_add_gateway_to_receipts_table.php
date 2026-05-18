<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la columna `gateway` a la tabla receipts.
 *
 * gateway  = procesador del pago (stripe, conekta, mercadopago, manual, etc.)
 * payment_method = tipo real de tarjeta (Tarjeta de crédito Visa ****4242)
 *
 * Los registros existentes se inicializan en 'stripe' ya que ese es el único
 * gateway integrado hasta ahora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            // Después de payment_reference para agrupar lógicamente
            $table->string('gateway', 50)->nullable()->default('stripe')->after('payment_reference');
        });

        // Backfill: todos los registros previos vienen de Stripe
        DB::table('receipts')->update(['gateway' => 'stripe']);
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropColumn('gateway');
        });
    }
};
