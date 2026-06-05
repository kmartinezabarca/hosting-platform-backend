<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega soporte para planes gratuitos y de prueba.
 *
 * service_plans:
 *   plan_type           — 'paid' | 'free' | 'trial'  (default: 'paid')
 *   trial_days          — Duración del periodo de prueba en días (solo plan_type='trial')
 *   converts_to_plan_id — FK al plan de pago al que se convierte al terminar el trial (nullable)
 *
 * services:
 *   trial_ends_at  — Fecha/hora en que termina el periodo de prueba (nullable)
 *   plan_type      — Snapshot del tipo de plan en el momento de contratación
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── service_plans ────────────────────────────────────────────────────
        Schema::table('service_plans', function (Blueprint $table) {
            $table->enum('plan_type', ['paid', 'free', 'trial'])
                ->default('paid')
                ->after('is_active')
                ->comment('Tipo de plan: paid=cobro normal, free=gratis permanente, trial=prueba gratuita');

            $table->unsignedTinyInteger('trial_days')
                ->nullable()
                ->after('plan_type')
                ->comment('Días del periodo de prueba (solo cuando plan_type=trial)');

            $table->unsignedBigInteger('converts_to_plan_id')
                ->nullable()
                ->after('trial_days')
                ->comment('Plan de pago al que convierte al terminar el trial');

            $table->foreign('converts_to_plan_id')
                ->references('id')
                ->on('service_plans')
                ->nullOnDelete();
        });

        // ── services ─────────────────────────────────────────────────────────
        Schema::table('services', function (Blueprint $table) {
            $table->timestamp('trial_ends_at')
                ->nullable()
                ->after('next_due_date')
                ->comment('Cuándo expira el periodo de prueba (null = no es trial)');

            $table->string('plan_type', 10)
                ->default('paid')
                ->after('trial_ends_at')
                ->comment('Snapshot del plan_type al momento de contratar');
        });

        // Backfill: todos los registros existentes son planes pagados
        DB::table('service_plans')->update(['plan_type' => 'paid']);
        DB::table('services')->update(['plan_type' => 'paid']);
    }

    public function down(): void
    {
        Schema::table('service_plans', function (Blueprint $table) {
            $table->dropForeign(['converts_to_plan_id']);
            $table->dropColumn(['plan_type', 'trial_days', 'converts_to_plan_id']);
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['trial_ends_at', 'plan_type']);
        });
    }
};
