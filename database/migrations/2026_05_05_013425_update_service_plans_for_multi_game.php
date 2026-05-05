<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Limpia service_plans para soportar múltiples juegos por plan.
 *
 * ANTES: el plan tenía un nest_id y egg_id hardcodeados → solo un juego posible.
 * AHORA: el cliente elige el juego al contratar, el plan solo define los recursos.
 *
 * Se eliminan pterodactyl_nest_id y pterodactyl_egg_id del plan.
 * Se agrega allowed_nest_ids para que el admin pueda restringir qué nests
 * (categorías de juego) están disponibles en este plan (null = todos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_plans', function (Blueprint $table) {
            // Array de nest IDs permitidos para este plan (null = todos los nests activos).
            // Ejemplo: [1, 5] → solo Minecraft y Source Games.
            $table->json('allowed_nest_ids')
                ->nullable()
                ->after('pterodactyl_node_id')
                ->comment('IDs de nests permitidos para este plan (null = todos). Ej: [1, 5]');

            // Límite de jugadores por defecto para el plan (se usa para MAX_PLAYERS).
            // Puede sobreescribirse en specifications.players.
            $table->unsignedSmallInteger('max_players')
                ->nullable()
                ->after('allowed_nest_ids')
                ->comment('Número máximo de jugadores para este plan (usado en MAX_PLAYERS de Pterodactyl)');

            // Eliminar columnas que ya no tienen sentido en el plan
            // (el egg se elige por servicio, no por plan)
            $table->dropColumn(['pterodactyl_nest_id', 'pterodactyl_egg_id']);
        });
    }

    public function down(): void
    {
        Schema::table('service_plans', function (Blueprint $table) {
            $table->dropColumn(['allowed_nest_ids', 'max_players']);

            $table->unsignedInteger('pterodactyl_nest_id')->nullable()->after('pterodactyl_node_id');
            $table->unsignedInteger('pterodactyl_egg_id')->nullable()->after('pterodactyl_nest_id');
        });
    }
};
