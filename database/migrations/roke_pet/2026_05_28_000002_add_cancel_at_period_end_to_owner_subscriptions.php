<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soporte para mostrar "tu plan se cancela el …" cuando el dueño cancela desde
 * el billing portal de Stripe (cancelación al final del periodo). Stripe marca
 * cancel_at_period_end=true y mantiene la suscripción activa hasta el fin del
 * periodo; el webhook lo refleja aquí para que la UI lo muestre.
 */
return new class extends Migration
{
    protected $connection = 'roke_pet';

    public function up(): void
    {
        Schema::connection('roke_pet')->table('owner_subscriptions', function (Blueprint $table) {
            $table->boolean('cancel_at_period_end')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::connection('roke_pet')->table('owner_subscriptions', function (Blueprint $table) {
            $table->dropColumn('cancel_at_period_end');
        });
    }
};
