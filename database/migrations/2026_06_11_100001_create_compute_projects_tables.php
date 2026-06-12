<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plano de cómputo — proyectos, ambientes y variables de entorno.
 *
 * Project agrupa los ambientes de una misma aplicación/repo. Environment es la
 * unidad de aislamiento (production/staging/preview): las env vars y los
 * recursos cuelgan del ambiente, nunca del proyecto, para que un preview de PR
 * jamás herede secretos de producción.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Instalaciones de la GitHub App por equipo (semana 2 la puebla; la
        // tabla nace ahora porque projects la referencia).
        Schema::create('github_installations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('installation_id')->unique();
            $table->string('account_login');
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->foreignId('github_installation_id')->nullable()
                ->constrained('github_installations')->nullOnDelete();
            $table->string('repo_full_name')->nullable();   // "org/repo" — solo display
            $table->string('default_branch')->nullable();
            $table->json('detected_stack')->nullable();     // salida del motor de detección
            $table->timestamps();
            $table->timestamp('archived_at')->nullable();

            $table->unique(['team_id', 'slug']);
        });

        Schema::create('environments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('type', 30)->default('production'); // production|staging|preview|development
            $table->string('branch')->nullable();
            $table->boolean('auto_deploy')->default(true);
            // Previews de PR: efímeros, barridos por scheduler al expirar.
            $table->boolean('ephemeral')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'slug']);
        });

        Schema::create('env_vars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            // Cifrado con cast `encrypted` del modelo. API de solo escritura:
            // los valores nunca se devuelven en claro (solo nombres + máscara).
            $table->text('value_encrypted')->nullable();
            $table->boolean('is_secret')->default(true);
            $table->string('source', 30)->default('user'); // user|detection|platform
            $table->timestamps();

            $table->unique(['environment_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('env_vars');
        Schema::dropIfExists('environments');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('github_installations');
    }
};
