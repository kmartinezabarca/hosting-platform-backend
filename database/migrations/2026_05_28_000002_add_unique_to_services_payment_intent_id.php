<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convierte el índice de `services.payment_intent_id` en UNIQUE.
 *
 * Esto blinda el sistema contra DOBLE PROVISIONING: un mismo PaymentIntent
 * de Stripe (reintento, doble click, webhook duplicado) ya no podrá crear
 * dos servicios. En MySQL un índice UNIQUE permite múltiples NULL, por lo
 * que los planes free/trial (sin PaymentIntent) no se ven afectados.
 *
 * Nota: si existieran filas con un mismo payment_intent_id no-null, esta
 * migración fallaría. Primero se deduplica conservando el servicio más
 * antiguo y dejando null el resto (caso anómalo, sólo posible por el bug
 * que esta migración corrige).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Deduplicar payment_intent_id repetidos antes de imponer el unique.
        $duplicates = DB::table('services')
            ->select('payment_intent_id')
            ->whereNotNull('payment_intent_id')
            ->groupBy('payment_intent_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('payment_intent_id');

        foreach ($duplicates as $pi) {
            $ids = DB::table('services')
                ->where('payment_intent_id', $pi)
                ->orderBy('id')
                ->pluck('id');

            // Conserva el primero; libera el payment_intent_id del resto.
            DB::table('services')
                ->whereIn('id', $ids->slice(1)->values())
                ->update(['payment_intent_id' => null]);
        }

        Schema::table('services', function (Blueprint $table) {
            // Quitar el índice plano previo (creado por la migración original).
            try {
                $table->dropIndex(['payment_intent_id']);
            } catch (\Throwable $e) {
                // El índice puede no existir en algunos entornos — continuar.
            }
            $table->unique('payment_intent_id', 'services_payment_intent_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropUnique('services_payment_intent_id_unique');
            $table->index('payment_intent_id');
        });
    }
};
