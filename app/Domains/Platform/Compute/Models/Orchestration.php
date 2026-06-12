<?php

namespace App\Domains\Platform\Compute\Models;

use App\Traits\HasUuidColumn;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Estado persistido de una saga (provision_app, deploy, rollback…).
 * Generaliza ProvisioningJob: pasos serializados en `steps`, reanudables
 * tras fallo de proveedor. Los clientes la consultan vía
 * GET /v2/orchestrations/{uuid} o siguen el progreso por Reverb.
 */
class Orchestration extends Model
{
    use HasFactory, HasUuidColumn;

    protected $fillable = [
        'uuid',
        'resource_id',
        'deployment_id',
        'flow',
        'state',
        'steps',
        'context',
        'attempts',
        'last_error',
        'completed_at',
        'failed_at',
    ];

    protected $casts = [
        'steps'        => 'array',
        'context'      => 'array',
        'attempts'     => 'integer',
        'completed_at' => 'datetime',
        'failed_at'    => 'datetime',
    ];

    /** Lee un valor de la bolsa de contexto compartida entre pasos. */
    public function getContext(string $key, mixed $default = null): mixed
    {
        return data_get($this->context, $key, $default);
    }

    /** Escribe y persiste un valor de contexto (visible para pasos siguientes). */
    public function setContext(string $key, mixed $value): void
    {
        $context = $this->context ?? [];
        data_set($context, $key, $value);
        $this->update(['context' => $context]);
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    public function isFinished(): bool
    {
        return $this->completed_at !== null || $this->failed_at !== null;
    }
}
