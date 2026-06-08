<?php

namespace App\Domains\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Configuración específica de Coolify (web hosting / apps) para un service_plan (1:1).
 */
class CoolifyPlanConfig extends Model
{
    protected $fillable = [
        'service_plan_id',
        'build_pack',
        'db_enabled',
        'db_type',
    ];

    protected $casts = [
        'db_enabled' => 'boolean',
    ];

    public function servicePlan(): BelongsTo
    {
        return $this->belongsTo(ServicePlan::class);
    }
}
