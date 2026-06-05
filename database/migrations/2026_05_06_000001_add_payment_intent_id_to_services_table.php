<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la columna payment_intent_id a la tabla services.
 *
 * Esta columna almacena el ID del PaymentIntent de Stripe asociado
 * al pago inicial de contratación del servicio, permitiendo rastrear
 * y reconciliar pagos directamente desde el registro del servicio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            if (! Schema::hasColumn('services', 'payment_intent_id')) {
                $table->string('payment_intent_id')->nullable()->after('notes')
                    ->comment('ID del PaymentIntent de Stripe usado al contratar el servicio');
                $table->index('payment_intent_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            if (Schema::hasColumn('services', 'payment_intent_id')) {
                $table->dropIndex(['payment_intent_id']);
                $table->dropColumn('payment_intent_id');
            }
        });
    }
};
