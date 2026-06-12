<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gate de confirmación de acciones del agente (mes 2, blueprint doc 07 §6.3).
 *
 * - `arguments` pasa a longText porque se cifra en reposo (puede contener el
 *   valor de una variable secreta que la IA propone definir).
 * - `summary` guarda la descripción legible que se muestra al usuario para
 *   confirmar, sin recomputarla.
 * - `message_id` ata la acción al mensaje del asistente que la propuso.
 *
 * La tabla nace vacía (v1 era solo lectura), así que los cambios son seguros.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_actions', function (Blueprint $table) {
            $table->longText('arguments')->change();
            $table->string('summary')->nullable()->after('arguments');
            $table->foreignId('message_id')->nullable()->after('conversation_id')
                ->constrained('ai_messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_actions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('message_id');
            $table->dropColumn('summary');
            $table->json('arguments')->change();
        });
    }
};
