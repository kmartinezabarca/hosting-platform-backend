<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado de aprovisionamiento a nivel de servicio para banners y diagnóstico.
 *
 * not_required → el plan no requiere provisioning externo
 * pending      → en cola, aún no aprovisionado
 * provisioning → en curso
 * succeeded    → aprovisionado correctamente
 * failed       → falló tras agotar reintentos (requiere intervención / soporte)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            if (! Schema::hasColumn('services', 'provisioning_status')) {
                $table->string('provisioning_status')->nullable()->after('suspension_reason');
            }
            if (! Schema::hasColumn('services', 'provisioning_error')) {
                $table->text('provisioning_error')->nullable()->after('provisioning_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['provisioning_status', 'provisioning_error']);
        });
    }
};
