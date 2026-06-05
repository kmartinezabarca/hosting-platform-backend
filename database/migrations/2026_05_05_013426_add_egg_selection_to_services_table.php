<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la elección de juego (egg) a la tabla de servicios.
 *
 * El egg ya no vive en service_plans (donde era igual para todos los
 * servicios del mismo plan). Ahora cada servicio guarda el egg que el
 * cliente eligió al contratar, permitiendo:
 *
 *   - Plan "Basic" → Minecraft Paper    (servicio A)
 *   - Plan "Basic" → Rust               (servicio B)
 *   - Plan "Pro"   → Valheim            (servicio C)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            if (! Schema::hasColumn('services', 'selected_egg_id')) {
                // FK al catálogo de eggs — nullOnDelete para que el servicio no
                // desaparezca si el admin elimina un egg del catálogo.
                $table->unsignedBigInteger('selected_egg_id')
                    ->nullable()
                    ->after('plan_id')
                    ->comment('Egg elegido por el cliente al contratar (FK a pterodactyl_eggs.id)');

                $table->foreign('selected_egg_id')
                    ->references('id')
                    ->on('pterodactyl_eggs')
                    ->nullOnDelete();
            } else {
                // Column already exists — ensure FK is wired correctly.
                // If the FK is missing (e.g., column was added without it), add it.
                try {
                    $table->foreign('selected_egg_id')
                        ->references('id')
                        ->on('pterodactyl_eggs')
                        ->nullOnDelete();
                } catch (\Throwable) {
                    // FK already exists — nothing to do.
                }
            }

            // MAX_PLAYERS resuelto en el momento de la contratación
            // para que quede un snapshot inmutable aunque el plan cambie.
            if (! Schema::hasColumn('services', 'max_players')) {
                $table->unsignedSmallInteger('max_players')
                    ->nullable()
                    ->after('selected_egg_id')
                    ->comment('Número máximo de jugadores según el plan al momento de contratar');
            }
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropForeign(['selected_egg_id']);
            $table->dropColumn(['selected_egg_id', 'max_players']);
        });
    }
};
