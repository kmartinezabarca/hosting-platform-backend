<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data migration — corrige los límites de Pterodactyl en los planes de juego.
 *
 * El seeder original no guardaba pterodactyl_limits / pterodactyl_feature_limits,
 * por lo que todos los servidores se creaban con los valores por defecto del config
 * (1 GB RAM, 5 GB disco, 1 vCPU) sin importar el plan contratado.
 *
 * Esta migración actualiza los planes existentes con los valores correctos basados
 * en las especificaciones de cada plan.
 *
 * Recuerda ajustar nest_id y egg_id a los de tu instalación de Pterodactyl si
 * difieren de los valores por defecto (1/1).
 */
return new class extends Migration
{
    /**
     * Mapa de slug → configuración correcta de Pterodactyl.
     *
     * memory  = MB de RAM        (1 GB = 1024, 2 GB = 2048, …)
     * disk    = MB de disco      (10 GB = 10240, 25 GB = 25600, …)
     * cpu     = % de CPU         (100 = 1 vCPU, 200 = 2 vCPU, 400 = 4 vCPU, …)
     * swap    = MB de swap       (0 = sin swap — recomendado para Minecraft)
     * io      = peso de I/O      (500 = valor estándar Pterodactyl)
     */
    private array $planConfig = [
        'gameserver-trial' => [
            'limits'         => ['memory' => 1024,  'swap' => 0, 'disk' => 10240,  'io' => 500, 'cpu' => 100],
            'feature_limits' => ['databases' => 0,  'backups' => 1,  'allocations' => 1],
            'nest_id'        => 1,
            'egg_id'         => 1,
            'docker_image'   => 'ghcr.io/pterodactyl/yolks:java_21',
        ],
        'minecraft-basic' => [
            'limits'         => ['memory' => 2048,  'swap' => 0, 'disk' => 25600,  'io' => 500, 'cpu' => 100],
            'feature_limits' => ['databases' => 1,  'backups' => 2,  'allocations' => 1],
            'nest_id'        => 1,
            'egg_id'         => 1,
            'docker_image'   => 'ghcr.io/pterodactyl/yolks:java_21',
        ],
        'minecraft-pro' => [
            'limits'         => ['memory' => 4096,  'swap' => 0, 'disk' => 51200,  'io' => 500, 'cpu' => 200],
            'feature_limits' => ['databases' => 2,  'backups' => 5,  'allocations' => 1],
            'nest_id'        => 1,
            'egg_id'         => 1,
            'docker_image'   => 'ghcr.io/pterodactyl/yolks:java_21',
        ],
        'minecraft-enterprise' => [
            'limits'         => ['memory' => 8192,  'swap' => 0, 'disk' => 102400, 'io' => 500, 'cpu' => 400],
            'feature_limits' => ['databases' => 5,  'backups' => 15, 'allocations' => 2],
            'nest_id'        => 1,
            'egg_id'         => 1,
            'docker_image'   => 'ghcr.io/pterodactyl/yolks:java_21',
        ],
    ];

    public function up(): void
    {
        foreach ($this->planConfig as $slug => $config) {
            DB::table('service_plans')
                ->where('slug', $slug)
                ->update([
                    'pterodactyl_limits'         => json_encode($config['limits']),
                    'pterodactyl_feature_limits' => json_encode($config['feature_limits']),
                    'pterodactyl_nest_id'        => $config['nest_id'],
                    'pterodactyl_egg_id'         => $config['egg_id'],
                    'pterodactyl_docker_image'   => $config['docker_image'],
                    'updated_at'                 => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Revertir a null (estado original antes de la corrección)
        DB::table('service_plans')
            ->whereIn('slug', array_keys($this->planConfig))
            ->update([
                'pterodactyl_limits'         => null,
                'pterodactyl_feature_limits' => null,
                'pterodactyl_nest_id'        => null,
                'pterodactyl_egg_id'         => null,
                'pterodactyl_docker_image'   => null,
                'updated_at'                 => now(),
            ]);
    }
};
