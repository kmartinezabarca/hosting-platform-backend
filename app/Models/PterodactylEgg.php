<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class PterodactylEgg extends Model
{
    protected $fillable = [
        'uuid',
        'ptero_nest_id',
        'ptero_egg_id',
        'nest_name',
        'nest_identifier',
        'nest_description',
        'egg_name',
        'egg_description',
        'egg_author',
        'docker_image',
        'startup',
        'variables',
        'config_files',
        'is_active',
        'display_name',
        'icon_url',
        'sort_order',
        'synced_at',
    ];

    protected $casts = [
        'variables'    => 'array',
        'config_files' => 'array',
        'is_active'    => 'boolean',
        'synced_at'    => 'datetime',
        'sort_order'   => 'integer',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Scopes
    // ─────────────────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForNests(Builder $query, array $nestIds): Builder
    {
        return $query->whereIn('ptero_nest_id', $nestIds);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Relaciones
    // ─────────────────────────────────────────────────────────────────────────

    /** Servicios que usan este egg. */
    public function services()
    {
        return $this->hasMany(Service::class, 'selected_egg_id');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** Nombre para mostrar al cliente (display_name si está seteado, egg_name si no). */
    public function getDisplayLabel(): string
    {
        return $this->display_name ?: $this->egg_name;
    }

    /**
     * Variables del egg en formato key → valor por defecto.
     * Útil para pre-rellenar el environment al crear el servidor.
     */
    public function defaultEnvironment(): array
    {
        $env = [];
        foreach ($this->variables ?? [] as $var) {
            $key = $var['env_variable'] ?? null;
            if ($key) {
                $env[$key] = $var['default_value'] ?? '';
            }
        }
        return $env;
    }

    /**
     * Payload listo para el API pública del frontend.
     * No expone datos internos de Pterodactyl.
     */
    public function toClientArray(): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->getDisplayLabel(),
            'description' => $this->egg_description,
            'nest'        => $this->nest_name,
            'nest_id'     => $this->ptero_nest_id,
            'icon_url'    => $this->icon_url,
        ];
    }
}
