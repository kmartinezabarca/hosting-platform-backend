<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plano de cómputo — equipos (tenancy).
 *
 * Todo el plano de cómputo (projects → environments → resources → deployments)
 * pertenece a un Team, nunca a un User directo. Cada usuario recibe un equipo
 * personal (is_personal=true) vía `php artisan platform:compute:backfill-teams`,
 * de modo que la autorización siempre pasa por membresía de equipo.
 *
 * Las columnas de rol/tier son strings cortos validados por enums PHP
 * (App\Domains\Platform\Compute\Enums\*) en lugar de columnas ENUM de MySQL:
 * extender un ENUM requiere ALTER TABLE con raw SQL (ver
 * extend_services_status_enum) y este esquema va a evolucionar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('plan_tier', 30)->default('free');
            // Equipo personal auto-creado en registro/backfill; no se puede
            // eliminar ni transferir, y es el default para nuevos proyectos.
            $table->boolean('is_personal')->default(false);
            $table->timestamps();

            $table->index('owner_user_id');
        });

        Schema::create('team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 30)->default('developer'); // owner|admin|developer|billing|viewer
            $table->timestamps();

            $table->unique(['team_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_members');
        Schema::dropIfExists('teams');
    }
};
