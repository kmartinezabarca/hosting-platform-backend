<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos de conexión de recursos de base de datos (mes 2, self-service de DB).
 *
 * Se guarda en una columna propia cifrada en reposo (host/puerto/usuario y,
 * sobre todo, la contraseña) en vez de en `spec`, porque `spec` se serializa
 * en las respuestas de API y la contraseña jamás debe salir. Null para apps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resources', function (Blueprint $table) {
            $table->text('connection_encrypted')->nullable()->after('spec');
        });
    }

    public function down(): void
    {
        Schema::table('resources', function (Blueprint $table) {
            $table->dropColumn('connection_encrypted');
        });
    }
};
