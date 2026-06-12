<?php

namespace App\Domains\Platform\Compute\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Muestra puntual de uso de un recurso (alimenta métricas, metering de
 * billing y el contexto del asistente de IA). Sin updated_at: append-only.
 */
class UsageSample extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'resource_id',
        'sampled_at',
        'cpu_pct',
        'ram_mb',
        'disk_mb',
        'net_rx_mb',
        'net_tx_mb',
    ];

    protected $casts = [
        'sampled_at' => 'datetime',
        'cpu_pct'    => 'decimal:2',
    ];

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }
}
