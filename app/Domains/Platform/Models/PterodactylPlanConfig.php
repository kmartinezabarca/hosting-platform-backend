<?php

namespace App\Domains\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Configuración específica de Pterodactyl para un service_plan (relación 1:1).
 *
 * Separa la config de game servers de la tabla service_plans para no mezclar
 * concerns con Coolify/VPS/DB. Un game server corre UN solo egg.
 */
class PterodactylPlanConfig extends Model
{
    protected $fillable = [
        'service_plan_id',
        'egg',
        'version',
        'docker_image',
        'startup',
        'node_id',
        'max_players',
        'game_type',
        'environment',
        'limits',
        'feature_limits',
        'allowed_nest_ids',
        'game_runtime_options',
        'game_config_schema',
    ];

    protected $casts = [
        'node_id'              => 'integer',
        'max_players'          => 'integer',
        'environment'          => 'array',
        'limits'               => 'array',
        'feature_limits'       => 'array',
        'allowed_nest_ids'     => 'array',
        'game_runtime_options' => 'array',
        'game_config_schema'   => 'array',
    ];

    public function servicePlan(): BelongsTo
    {
        return $this->belongsTo(ServicePlan::class);
    }
}
