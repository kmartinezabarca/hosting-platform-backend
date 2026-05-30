<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soporte para cancelación al final del periodo pagado.
 *
 * Cuando el cliente cancela, NO se corta el servicio de inmediato: se marca
 * cancel_at_period_end = true en Stripe y localmente. El servicio sigue activo
 * hasta ends_at (fin del periodo pagado); al llegar esa fecha, Stripe emite
 * customer.subscription.deleted y recién entonces se desactiva el servicio.
 * El cliente puede reactivar (quitar la marca) antes de que termine el periodo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->boolean('cancel_at_period_end')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('cancel_at_period_end');
        });
    }
};
