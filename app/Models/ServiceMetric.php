<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot de métricas de un servicio de juego (Pterodactyl).
 * Muestreado cada 5 minutos por CollectServiceMetrics.
 * Se retienen 48 horas de historial.
 */
class ServiceMetric extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'service_id',
        'cpu_percent',
        'memory_bytes',
        'memory_limit_bytes',
        'disk_bytes',
        'disk_limit_bytes',
        'network_rx_bytes',
        'network_tx_bytes',
        'state',
        'sampled_at',
    ];

    protected $casts = [
        'cpu_percent'        => 'float',
        'memory_bytes'       => 'integer',
        'memory_limit_bytes' => 'integer',
        'disk_bytes'         => 'integer',
        'disk_limit_bytes'   => 'integer',
        'network_rx_bytes'   => 'integer',
        'network_tx_bytes'   => 'integer',
        'sampled_at'         => 'datetime',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
