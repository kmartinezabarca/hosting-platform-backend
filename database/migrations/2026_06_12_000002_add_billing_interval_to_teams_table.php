<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mes 3 — annual billing: intervalo de facturación elegido por el equipo
 * (monthly|annual). El precio/stripe_price_id concreto se resuelve del catálogo
 * (config('compute.billing.pricing')) según plan_tier + billing_interval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->enum('billing_interval', ['monthly', 'annual'])
                ->default('monthly')
                ->after('plan_tier');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('billing_interval');
        });
    }
};
