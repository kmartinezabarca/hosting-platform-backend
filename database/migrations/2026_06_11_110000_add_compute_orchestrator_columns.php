<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columnas para el runner del orquestador (semana 3):
 *
 * - orchestrations.context: bolsa de datos compartida entre pasos de la saga
 *   (ids de proveedor en vuelo, offsets de logs, quién inició).
 * - projects.provider_meta: refs de proveedor a nivel proyecto (ej. el uuid
 *   del proyecto Coolify). Interno — jamás se serializa al cliente.
 * - deployments.provider_ref: id del deployment en el runtime (Coolify) para
 *   poder reanudar el polling tras un restart del worker.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orchestrations', function (Blueprint $table) {
            $table->json('context')->nullable()->after('steps');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->json('provider_meta')->nullable()->after('detected_stack');
        });

        Schema::table('deployments', function (Blueprint $table) {
            $table->string('provider_ref')->nullable()->after('status');
            $table->index('provider_ref');
        });
    }

    public function down(): void
    {
        Schema::table('orchestrations', fn (Blueprint $t) => $t->dropColumn('context'));
        Schema::table('projects', fn (Blueprint $t) => $t->dropColumn('provider_meta'));
        Schema::table('deployments', function (Blueprint $t) {
            $t->dropIndex(['provider_ref']);
            $t->dropColumn('provider_ref');
        });
    }
};
