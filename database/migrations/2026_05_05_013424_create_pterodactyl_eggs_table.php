<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de catálogo de juegos (eggs/nests) disponibles en Pterodactyl.
 *
 * Se sincroniza automáticamente vía `php artisan pterodactyl:sync-eggs`
 * (ejecutado por el scheduler cada hora).
 *
 * Cada fila representa un egg habilitado que el cliente puede elegir
 * al contratar un plan de Game Server.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pterodactyl_eggs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // ── IDs de Pterodactyl (composite unique) ─────────────────────
            $table->unsignedInteger('ptero_nest_id')->comment('Nest ID en el panel de Pterodactyl');
            $table->unsignedInteger('ptero_egg_id')->comment('Egg ID dentro del nest');
            $table->unique(['ptero_nest_id', 'ptero_egg_id'], 'pterodactyl_eggs_nest_egg_unique');

            // ── Metadata del nest ──────────────────────────────────────────
            $table->string('nest_name');
            $table->string('nest_identifier')->nullable()->comment('Identificador corto del nest');
            $table->text('nest_description')->nullable();

            // ── Metadata del egg ──────────────────────────────────────────
            $table->string('egg_name');
            $table->text('egg_description')->nullable();
            $table->string('egg_author')->nullable();

            // ── Configuración por defecto del egg ─────────────────────────
            $table->string('docker_image')->comment('Imagen Docker por defecto');
            $table->text('startup')->comment('Comando de startup por defecto');
            $table->json('variables')->nullable()
                ->comment('Variables de entorno con sus valores por defecto y reglas de validación');
            $table->json('config_files')->nullable()
                ->comment('Archivos de configuración adicionales del egg');

            // ── Control administrativo ────────────────────────────────────
            $table->boolean('is_active')->default(true)->index()
                ->comment('false = no aparece en la lista de juegos disponibles');
            $table->string('display_name')->nullable()
                ->comment('Nombre personalizado para mostrar al cliente (anula egg_name si está presente)');
            $table->string('icon_url')->nullable()
                ->comment('URL de ícono del juego para mostrar en la UI');
            $table->unsignedSmallInteger('sort_order')->default(0)->index();

            // ── Sync ──────────────────────────────────────────────────────
            $table->timestamp('synced_at')->nullable()
                ->comment('Última vez que se sincronizó desde la API de Pterodactyl');

            $table->timestamps();

            // Índices de búsqueda
            $table->index('ptero_nest_id');
            $table->index('ptero_egg_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pterodactyl_eggs');
    }
};
