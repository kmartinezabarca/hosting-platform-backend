<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 (expand) del rediseño de service_plans.
 *
 * Separa la configuración específica de cada provisioner en tablas 1:1 dedicadas,
 * para que service_plans deje de mezclar concerns (Pterodactyl / Hestia / Coolify
 * y a futuro VPS / DB). Esta migración es ADITIVA: solo crea tablas y backfillea
 * datos desde las columnas/JSON actuales. No elimina ninguna columna todavía
 * (eso ocurre en la migración "contract" posterior, una vez verificado el wiring).
 *
 * El egg, que en algunos planes se guardaba como lista de opciones, se colapsa a
 * UN solo egg (el primero) — un game server corre un único egg.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pterodactyl_plan_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_plan_id')->unique()->constrained('service_plans')->cascadeOnDelete();
            $table->string('egg')->nullable();
            $table->string('version')->nullable();
            $table->string('docker_image')->nullable();
            $table->text('startup')->nullable();
            $table->unsignedInteger('node_id')->nullable();
            $table->unsignedSmallInteger('max_players')->nullable();
            $table->string('game_type', 50)->nullable();
            $table->json('environment')->nullable();
            $table->json('limits')->nullable();
            $table->json('feature_limits')->nullable();
            $table->json('allowed_nest_ids')->nullable();
            $table->json('game_runtime_options')->nullable();
            $table->json('game_config_schema')->nullable();
            $table->timestamps();
        });

        Schema::create('coolify_plan_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_plan_id')->unique()->constrained('service_plans')->cascadeOnDelete();
            $table->string('build_pack')->default('static');
            $table->boolean('db_enabled')->default(false);
            $table->string('db_type')->default('mariadb');
            $table->timestamps();
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('coolify_plan_configs');
        Schema::dropIfExists('pterodactyl_plan_configs');
    }

    /**
     * Copia la config existente (columnas planas + provisioner_config JSON) a las
     * nuevas tablas 1:1, según el provisioner de cada plan.
     */
    private function backfill(): void
    {
        $now = now();

        DB::table('service_plans')->orderBy('id')->each(function ($plan) use ($now) {
            $config = $this->decodeJson($plan->provisioner_config);

            switch ($plan->provisioner) {
                case 'pterodactyl':
                    $egg = $config['egg'] ?? null;
                    if (is_array($egg)) {
                        $egg = $egg[0] ?? null; // colapsar lista → un solo egg
                    }

                    DB::table('pterodactyl_plan_configs')->insert([
                        'service_plan_id'      => $plan->id,
                        'egg'                  => is_string($egg) ? $egg : null,
                        'version'              => $config['version'] ?? null,
                        'docker_image'         => $plan->pterodactyl_docker_image ?? null,
                        'startup'              => $plan->pterodactyl_startup ?? null,
                        'node_id'              => $plan->pterodactyl_node_id ?? null,
                        'max_players'          => $plan->max_players ?? null,
                        'game_type'            => $plan->game_type ?? null,
                        'environment'          => $this->reencode($plan->pterodactyl_environment ?? ($config['environment'] ?? null)),
                        'limits'               => $this->reencode($plan->pterodactyl_limits ?? null),
                        'feature_limits'       => $this->reencode($plan->pterodactyl_feature_limits ?? null),
                        'allowed_nest_ids'     => $this->reencode($plan->allowed_nest_ids ?? null),
                        'game_runtime_options' => $this->reencode($plan->game_runtime_options ?? null),
                        'game_config_schema'   => $this->reencode($plan->game_config_schema ?? null),
                        'created_at'           => $now,
                        'updated_at'           => $now,
                    ]);
                    break;

                case 'coolify':
                    DB::table('coolify_plan_configs')->insert([
                        'service_plan_id' => $plan->id,
                        'build_pack'      => $config['build_pack'] ?? 'static',
                        'db_enabled'      => (bool) ($config['db_enabled'] ?? false),
                        'db_type'         => $config['db_type'] ?? 'mariadb',
                        'created_at'      => $now,
                        'updated_at'      => $now,
                    ]);
                    break;
            }
        });
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** Normaliza un valor (array o string JSON) a string JSON para columnas json, o null. */
    private function reencode(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return empty($value) ? null : json_encode($value);
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) && ! empty($decoded) ? json_encode($decoded) : null;
        }

        return null;
    }
};
