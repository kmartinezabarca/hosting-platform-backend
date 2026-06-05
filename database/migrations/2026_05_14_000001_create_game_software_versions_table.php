<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versiones de software de servidores de juego compatibles con Pterodactyl.
 *
 * Sustituye las llamadas a APIs de terceros (PaperMC, Mojang, Fabric, etc.)
 * por un registro propio con control total sobre qué versiones se exponen
 * a los clientes y cuáles son realmente compatibles con nuestros eggs.
 *
 * sort_order DESC: valor más alto = se muestra primero (versión más reciente).
 *
 * Comando de gestión: php artisan game:versions
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_software_versions', function (Blueprint $table) {
            $table->id();

            // Identificador del software: 'paper', 'vanilla', 'fabric', etc.
            // Coincide con los identificadores usados en SoftwareVersionService.
            $table->string('software_identifier', 50)->index();

            // Cadena de versión tal como se envía a Pterodactyl (p. ej. '1.21.4').
            $table->string('version', 50);

            // Si false, no se devuelve al cliente ni se oferta en la UI.
            $table->boolean('is_active')->default(true)->index();

            // Marca la versión recomendada para ese software (UI la destaca).
            $table->boolean('is_recommended')->default(false);

            // Orden de presentación: DESC → mayor valor = primera posición.
            // Versiones más nuevas reciben sort_order más alto.
            // Al agregar una nueva versión: max(sort_order) + 1 automáticamente.
            $table->smallInteger('sort_order')->default(0);

            // Notas opcionales para el equipo (p. ej. "Requiere Yolks: java_21").
            $table->string('notes', 500)->nullable();

            $table->timestamps();

            // Unicidad por software + versión
            $table->unique(['software_identifier', 'version']);

            // Índice compuesto para la consulta principal del servicio
            // Nombre explícito para respetar el límite de 64 chars de MySQL
            $table->index(['software_identifier', 'is_active', 'sort_order'], 'gsv_software_active_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_software_versions');
    }
};
