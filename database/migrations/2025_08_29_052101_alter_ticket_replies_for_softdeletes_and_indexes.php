<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ticket_replies', function (Blueprint $table) {
            // Soft Deletes (para que funcione el trait SoftDeletes del modelo)
            if (!Schema::hasColumn('ticket_replies', 'deleted_at')) {
                $table->softDeletes()->after('updated_at');
            }

            // Asegurar tipo/valor por defecto de is_internal (por claridad)
            // (Si ya existe con estas características, no pasará nada.)
            $table->boolean('is_internal')->default(false)->change();

            // Índices útiles para scopes y consultas comunes
            // (Agregar índices compuestos acelera las cargas por ticket y orden cronológico)
            if (!Schema::hasColumn('ticket_replies', 'attachments')) {
                // En caso de entornos donde aún no existe (tu tabla ya lo tiene, esto es defensivo)
                $table->json('attachments')->nullable()->after('is_internal');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ticket_replies', function (Blueprint $table) {
            // Quitar índices agregados por esta migración
            $table->dropIndex('ticket_replies_is_internal_idx');

            // Mantengo created_at/user_id porque probablemente existían antes
            // $table->dropIndex('ticket_replies_user_id_index');
            // $table->dropIndex('ticket_replies_created_at_index');

            // Remover soft deletes si quieres revertir completamente
            if (Schema::hasColumn('ticket_replies', 'deleted_at')) {
                $table->dropSoftDeletes();
            }

            // No eliminamos attachments porque ya existía en tu esquema original
            // y es parte del contrato del modelo.
        });
    }
};
