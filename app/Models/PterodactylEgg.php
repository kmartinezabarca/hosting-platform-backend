<?php

namespace App\Models;

use App\Enums\GameProtocol;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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

        'game_protocol',

        'synced_at',
    ];

    protected $casts = [
        'variables'      => 'array',
        'config_files'   => 'array',

        'is_active'      => 'boolean',
        'sort_order'     => 'integer',

        'synced_at'      => 'datetime',

        'game_protocol'  => GameProtocol::class,
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }

            // Default inteligente
            if (! $model->game_protocol) {
                $model->game_protocol = GameProtocol::JAVA;
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // Scopes
    // ─────────────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForNests(Builder $query, array $nestIds): Builder
    {
        return $query->whereIn('ptero_nest_id', $nestIds);
    }

    public function scopeProtocol(
        Builder $query,
        GameProtocol $protocol
    ): Builder {
        return $query->where('game_protocol', $protocol->value);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Relaciones
    // ─────────────────────────────────────────────────────────────────────

    public function services()
    {
        return $this->hasMany(Service::class, 'selected_egg_id');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    public function getDisplayLabel(): string
    {
        return $this->display_name ?: $this->egg_name;
    }

    /**
     * Nunca regresar null.
     */
    public function protocol(): GameProtocol
    {
        return $this->game_protocol ?? GameProtocol::JAVA;
    }

    public function isJava(): bool
    {
        return $this->protocol()->supportsJava();
    }

    public function isBedrock(): bool
    {
        return $this->protocol()->supportsBedrock();
    }

    public function usesSrvRecord(): bool
    {
        return $this->protocol()->usesSrvRecord();
    }

    public function shouldDisplayPort(): bool
    {
        return $this->protocol()->displayPort();
    }

    /**
     * Variables default del egg.
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
     * API frontend.
     */
    public function toClientArray(): array
    {
        return [
            'id'              => $this->id,

            'name'            => $this->getDisplayLabel(),

            'description'     => $this->egg_description,

            'nest'            => $this->nest_name,
            'nest_id'         => $this->ptero_nest_id,

            'icon_url'        => $this->icon_url,

            'protocol'        => $this->protocol()->value,
            'protocol_label'  => $this->protocol()->label(),

            'supports_java'   => $this->isJava(),
            'supports_bedrock'=> $this->isBedrock(),
        ];
    }
}
