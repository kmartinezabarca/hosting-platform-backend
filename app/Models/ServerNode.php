<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Registro de nodos de infraestructura.
 *
 * Sirve como catálogo local de nodos Pterodactyl (y futuros Proxmox/dedicados).
 * Cuando `node_type = 'pterodactyl'` y `pterodactyl_node_id` está establecido,
 * PterodactylService::autoSelectNode() restringe la selección a estos nodos.
 * Si no hay nodos registrados, se usa el fallback de Pterodactyl (todos los nodos).
 */
class ServerNode extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'name',
        'hostname',
        'ip_address',
        'location',
        'node_type',
        'specifications',
        'api_credentials',
        'status',
        'max_services',
        'current_services',
        'pterodactyl_node_id',
        'priority',
    ];

    protected $casts = [
        'specifications'      => 'array',
        'api_credentials'     => 'encrypted:array',
        'max_services'        => 'integer',
        'current_services'    => 'integer',
        'pterodactyl_node_id' => 'integer',
        'priority'            => 'integer',
    ];

    protected $hidden = ['api_credentials'];

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopePterodactyl(Builder $query): Builder
    {
        return $query->where('node_type', 'pterodactyl');
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->active()->where(function (Builder $q) {
            $q->where('max_services', 0)
              ->orWhereColumn('current_services', '<', 'max_services');
        });
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function hasCapacity(): bool
    {
        return $this->max_services === 0 || $this->current_services < $this->max_services;
    }

    public function incrementServiceCount(): void
    {
        $this->increment('current_services');
    }

    public function decrementServiceCount(): void
    {
        if ($this->current_services > 0) {
            $this->decrement('current_services');
        }
    }
}
