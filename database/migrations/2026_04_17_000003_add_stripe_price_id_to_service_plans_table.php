<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('service_plans', 'stripe_price_id')) {
                // ID del Price de Stripe para suscripciones recurrentes.
                // Se configura manualmente en el panel de Stripe y se registra aquí.
                // Si es null, create_subscription se ignorará de forma segura.
                $table->string('stripe_price_id')->nullable()->after('setup_fee');
            }
        });
    }

    public function down(): void
    {
        Schema::table('service_plans', function (Blueprint $table) {
            if (Schema::hasColumn('service_plans', 'stripe_price_id')) {
                $table->dropColumn('stripe_price_id');
            }
        });
    }
};
