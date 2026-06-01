<?php

namespace App\Domains\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una muestra de health check HTTP de un servicio de hosting.
 *
 * @see \App\Domains\Platform\Services\HostingHealthService
 */
class ServiceHealthCheck extends Model
{
    protected $fillable = [
        'service_id',
        'ok',
        'http_status',
        'latency_ms',
        'error',
        'checked_at',
    ];

    protected $casts = [
        'ok'         => 'boolean',
        'http_status'=> 'integer',
        'latency_ms' => 'integer',
        'checked_at' => 'datetime',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
