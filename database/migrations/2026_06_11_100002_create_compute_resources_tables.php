<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plano de cómputo — recursos y referencias a proveedores.
 *
 * Resource es la unidad desplegable (app, base de datos, game server…) con su
 * estado deseado en `spec` (JSON). Los IDs de Coolify/Pterodactyl viven SOLO
 * en resource_provider_refs: ningún transformer de API debe serializar esa
 * tabla — así la regla "el cliente nunca ve los paneles" es estructural.
 *
 * service_id enlaza con el plano de billing existente (renovaciones y
 * suspensiones de ServiceSuspensionService siguen funcionando sin cambios);
 * es nullable porque los recursos de trial/free aún no tienen suscripción.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resources', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30);    // app|static_site|database|redis|game_server|compose
            $table->string('name');
            $table->string('status', 30)->default('creating');
            $table->json('spec');          // estado deseado: build, cpu, ram_mb, disk_mb, region, game…
            $table->foreignId('service_id')->nullable()
                ->constrained('services')->nullOnDelete();
            $table->json('health')->nullable(); // último snapshot (cpu%, ram%, uptime)
            $table->timestamps();
            $table->softDeletes();

            $table->index(['environment_id', 'kind']);
            $table->index('status');
            $table->index('service_id');
        });

        Schema::create('resource_provider_refs', function (Blueprint $table) {
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30); // coolify|pterodactyl|cloudflare
            $table->string('external_id');
            $table->json('external_meta')->nullable();
            $table->timestamps();

            $table->primary(['resource_id', 'provider']);
            $table->index(['provider', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_provider_refs');
        Schema::dropIfExists('resources');
    }
};
