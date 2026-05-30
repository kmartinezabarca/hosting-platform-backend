<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Job de aprovisionamiento de un servicio en un proveedor (Pterodactyl/Coolify).
 *
 * @see \App\Services\ProvisioningService
 */
class ProvisioningJob extends Model
{
    public const PROVIDER_PTERODACTYL = 'pterodactyl';
    public const PROVIDER_COOLIFY     = 'coolify';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_RUNNING   = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED    = 'failed';

    protected $fillable = [
        'service_id',
        'provider',
        'status',
        'attempts',
        'max_attempts',
        'available_at',
        'last_error',
        'payload',
        'processed_at',
    ];

    protected $casts = [
        'attempts'     => 'integer',
        'max_attempts' => 'integer',
        'available_at' => 'datetime',
        'processed_at' => 'datetime',
        'payload'      => 'array',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function isExhausted(): bool
    {
        return $this->attempts >= $this->max_attempts;
    }

    public function scopeRetryable($query)
    {
        return $query
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_FAILED])
            ->whereColumn('attempts', '<', 'max_attempts')
            ->where(function ($q) {
                $q->whereNull('available_at')->orWhere('available_at', '<=', now());
            });
    }
}
