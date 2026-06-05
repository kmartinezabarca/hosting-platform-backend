<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega claves SAT a invoice_items y service_plans.
 *
 * Campos en invoice_items:
 *   sat_clave_prod_serv  — Clave del producto/servicio SAT (ej. 81161501)
 *   sat_clave_unidad     — Clave de unidad SAT (ej. E48)
 *
 * Campos en service_plans:
 *   sat_clave_prod_serv  — Clave SAT del plan, se hereda al ítem al contratar
 *   sat_clave_unidad     — Clave de unidad SAT del plan
 *
 * Backfill: claves globales del config de Facturama.
 *
 * Catálogo SAT de referencia:
 *   81161501 — Servicios de alojamiento de servidores
 *   81161500 — Servicios de tecnología de la información
 *   E48      — Unidad de servicio (servicios digitales)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── invoice_items ────────────────────────────────────────────────────
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->string('sat_clave_prod_serv', 10)
                ->nullable()
                ->comment('Clave SAT del producto/servicio (catálogo c_ClaveProdServ)')
                ->after('unit_price');

            $table->string('sat_clave_unidad', 3)
                ->nullable()
                ->comment('Clave SAT de unidad de medida (catálogo c_ClaveUnidad)')
                ->after('sat_clave_prod_serv');
        });

        // ── service_plans ────────────────────────────────────────────────────
        Schema::table('service_plans', function (Blueprint $table) {
            $table->string('sat_clave_prod_serv', 10)
                ->nullable()
                ->comment('Clave SAT del producto/servicio para CFDI')
                ->after('sort_order');

            $table->string('sat_clave_unidad', 3)
                ->nullable()
                ->default('E48')
                ->comment('Clave SAT de unidad de medida para CFDI')
                ->after('sat_clave_prod_serv');
        });

        // ── Backfill invoice_items ────────────────────────────────────────────
        $defaultClave  = config('facturama.clave_prod_serv', '81161501');
        $defaultUnidad = config('facturama.clave_unidad', 'E48');

        DB::table('invoice_items')->update([
            'sat_clave_prod_serv' => $defaultClave,
            'sat_clave_unidad'    => $defaultUnidad,
        ]);

        // ── Backfill service_plans ────────────────────────────────────────────
        DB::table('service_plans')->update([
            'sat_clave_prod_serv' => $defaultClave,
        ]);
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn(['sat_clave_prod_serv', 'sat_clave_unidad']);
        });

        Schema::table('service_plans', function (Blueprint $table) {
            $table->dropColumn(['sat_clave_prod_serv', 'sat_clave_unidad']);
        });
    }
};
