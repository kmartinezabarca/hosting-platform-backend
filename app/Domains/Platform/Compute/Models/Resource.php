<?php

namespace App\Domains\Platform\Compute\Models;

use App\Domains\Platform\Compute\Enums\ResourceKind;
use App\Domains\Platform\Compute\Enums\ResourceStatus;
use App\Domains\Platform\Models\Service;
use App\Traits\HasUuidColumn;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Unidad desplegable del plano de cómputo (app, base de datos, game server…).
 *
 * `spec` es el estado deseado; el estado real lo reporta `health`. El status
 * solo lo transiciona el orquestador. Los IDs de proveedor viven en
 * providerRefs y no deben exponerse en ninguna respuesta de API.
 */
class Resource extends Model
{
    use HasFactory, HasUuidColumn, SoftDeletes;

    protected $fillable = [
        'uuid',
        'environment_id',
        'kind',
        'name',
        'status',
        'spec',
        'service_id',
        'health',
    ];

    protected $casts = [
        'kind'   => ResourceKind::class,
        'status' => ResourceStatus::class,
        'spec'   => 'array',
        'health' => 'array',
    ];

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function providerRefs(): HasMany
    {
        return $this->hasMany(ResourceProviderRef::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function orchestrations(): HasMany
    {
        return $this->hasMany(Orchestration::class);
    }

    public function usageSamples(): HasMany
    {
        return $this->hasMany(UsageSample::class);
    }

    /** Enlace al plano de billing (nullable durante trial/free). */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** Equipo dueño, resuelto por la cadena environment → project → team. */
    public function team(): ?Team
    {
        return $this->environment?->project?->team;
    }

    public function providerRef(string $provider): ?ResourceProviderRef
    {
        return $this->providerRefs->firstWhere('provider', $provider);
    }
}
