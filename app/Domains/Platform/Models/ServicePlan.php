<?php

namespace App\Domains\Platform\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ServicePlan extends Model
{
    use HasFactory;

    protected $appends = [
        'pterodactyl_egg',
        'pterodactyl_version',
        'coolify_build_pack',
        'coolify_db_enabled',
    ];

    /**
     * Eager-load de las configs 1:1 por provisioner para evitar N+1 al serializar
     * planes (los accessors pterodactyl_* / coolify_* leen de aquí).
     */
    protected $with = [
        'pterodactylConfig',
        'coolifyConfig',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'uuid',
        'category_id',
        'slug',
        'name',
        'description',
        'base_price',
        'setup_fee',
        'stripe_price_id',    // Stripe Price ID — default/monthly (fallback para suscripciones)
        'stripe_product_id',  // Stripe Product ID — uno por plan
        'is_popular',
        'is_active',
        'sort_order',
        'specifications',
        // Aprovisionamiento automático
        'provisioner',
        'provisioner_config',

        'game_type',
        'game_runtime_options',
        'game_config_schema',
        // NOTE: pterodactyl_nest_id y pterodactyl_egg_id fueron eliminados de la tabla
        // en la migración update_service_plans_for_multi_game. El egg lo elige el cliente
        // al contratar y queda guardado en services.selected_egg_id.
        'pterodactyl_node_id',
        'pterodactyl_limits',
        'pterodactyl_feature_limits',
        'pterodactyl_environment',
        'pterodactyl_docker_image',
        'pterodactyl_startup',
        // Multi-game: nest IDs permitidos para este plan y max jugadores
        'allowed_nest_ids',
        'max_players',
        // Claves SAT para CFDI
        'sat_clave_prod_serv',
        'sat_clave_unidad',
        // Tipo de plan y trial
        'plan_type',
        'trial_days',
        'converts_to_plan_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'base_price'                 => 'decimal:2',
        'setup_fee'                  => 'decimal:2',
        'is_popular'                 => 'boolean',
        'is_active'                  => 'boolean',
        'specifications'             => 'array',
        'provisioner_config'         => 'array',
        'game_runtime_options'        => 'array',
        'game_config_schema'          => 'array',
        'pterodactyl_limits'         => 'array',
        'pterodactyl_feature_limits' => 'array',
        'pterodactyl_environment'    => 'array',
        'allowed_nest_ids'           => 'array',
        'max_players'                => 'integer',
        'trial_days'                 => 'integer',
        'converts_to_plan_id'        => 'integer',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->slug) && !empty($model->name)) {
                $model->slug = Str::slug($model->name);
            }
        });
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName()
    {
        return 'uuid';
    }

    /**
     * Scope a query to only include active plans.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include popular plans.
     */
    public function scopePopular($query)
    {
        return $query->where('is_popular', true);
    }

    /**
     * Get the category that owns the service plan.
     */
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the features for this service plan.
     */
    public function features()
    {
        return $this->hasMany(PlanFeature::class);
    }

    /**
     * Get the pricing for this service plan.
     */
    public function pricing()
    {
        return $this->hasMany(PlanPricing::class);
    }

    /**
     * Get the services for this plan.
     */
    public function services()
    {
        return $this->hasMany(Service::class, 'plan_id'); // FK en services.plan_id → service_plans.id
    }

    /**
     * Get price for a specific billing cycle.
     */
    public function getPriceForCycle($billingCycleId)
    {
        $pricing = $this->pricing()->where('billing_cycle_id', $billingCycleId)->first();
        return $pricing ? $pricing->price : null;
    }

    /**
     * Get all available pricing with billing cycle information.
     */
    public function getPricingWithCycles()
    {
        return $this->pricing()->with('billingCycle')->get();
    }

    /**
     * Get formatted specifications for display.
     */
    public function getFormattedSpecifications()
    {
        $specs = $this->specifications ?? [];
        $formatted = [];

        foreach ($specs as $key => $value) {
            $formatted[ucfirst(str_replace('_', ' ', $key))] = $value;
        }

        return $formatted;
    }

    // ── Plan type constants ───────────────────────────────────────────────────
    public const TYPE_PAID  = 'paid';
    public const TYPE_FREE  = 'free';
    public const TYPE_TRIAL = 'trial';

    // ── Plan type helpers ────────────────────────────────────────────────────

    /** Plan de pago normal — requiere tarjeta/Stripe. */
    public function isPaid(): bool
    {
        return ($this->plan_type ?? self::TYPE_PAID) === self::TYPE_PAID;
    }

    /** Plan gratuito permanente — nunca requiere pago. */
    public function isFree(): bool
    {
        return $this->plan_type === self::TYPE_FREE;
    }

    /** Plan de prueba — gratuito por `trial_days` días, luego suspende/convierte. */
    public function isTrial(): bool
    {
        return $this->plan_type === self::TYPE_TRIAL;
    }

    /** ¿El plan no requiere cobro al contratar? */
    public function isNoCharge(): bool
    {
        return $this->isFree() || $this->isTrial();
    }

    /** Relación con el plan de pago al que se convierte al terminar el trial. */
    public function convertsToPlan()
    {
        return $this->belongsTo(ServicePlan::class, 'converts_to_plan_id');
    }

    public function isPterodactylManaged(): bool
    {
        return $this->provisioner === 'pterodactyl';
    }

    public function isCoolifyManaged(): bool
    {
        return $this->provisioner === 'coolify';
    }

    public function isGameServerPlan(): bool
    {
        return $this->provisioner === 'pterodactyl' && !empty($this->game_type);
    }

    public function normalizedProvisionerConfig(): ?array
    {
        $config = $this->provisioner_config ?? [];

        if ($this->provisioner === 'pterodactyl') {
            return array_filter([
                'egg' => $this->pterodactyl_egg,
                'version' => $this->pterodactyl_version,
                'environment' => $this->pterodactyl_environment,
            ], fn ($value) => $value !== null);
        }

        if ($this->provisioner === 'coolify') {
            $cfg = $this->coolifyConfig;

            return [
                'build_pack' => $cfg?->build_pack ?? $config['build_pack'] ?? 'static',
                'db_enabled' => (bool) ($cfg?->db_enabled ?? $config['db_enabled'] ?? false),
                'db_type'    => $cfg?->db_type ?? $config['db_type'] ?? 'mariadb',
            ];
        }

        return empty($config) ? null : $config;
    }

    public function getProvisionerConfigAttribute(mixed $value): ?array
    {
        $config = $this->decodeJsonAttribute($value);

        if (! empty($config)) {
            return $config;
        }

        $provisioner = $this->attributes['provisioner'] ?? null;

        if ($provisioner === 'pterodactyl') {
            $environment = $this->decodeJsonAttribute($this->attributes['pterodactyl_environment'] ?? null);

            return array_filter([
                'environment' => $environment ?: null,
            ], fn ($item) => $item !== null);
        }

        if ($provisioner === 'coolify') {
            return [
                'build_pack' => 'static',
                'db_enabled' => false,
                'db_type'    => 'mariadb',
            ];
        }

        return null;
    }

    // ── Relaciones 1:1 con la config por provisioner ──────────────────────────

    public function pterodactylConfig(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(PterodactylPlanConfig::class);
    }

    public function coolifyConfig(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(CoolifyPlanConfig::class);
    }

    // ── Accessors de compatibilidad ───────────────────────────────────────────
    // Fuente: tabla de config 1:1 del provisioner; fallback a la columna/JSON
    // legacy mientras exista (hasta la migración "contract" que las elimina).
    // @deprecated El código nuevo debería usar $plan->pterodactylConfig->... etc.

    /** Lee un valor JSON crudo de las columnas legacy (decodificado a array o null). */
    private function legacyJson(string $attr): ?array
    {
        $decoded = $this->decodeJsonAttribute($this->attributes[$attr] ?? null);

        return empty($decoded) ? null : $decoded;
    }

    public function getPterodactylEggAttribute(): ?string
    {
        $egg = $this->pterodactylConfig?->egg ?? ($this->provisioner_config['egg'] ?? null);

        // Compatibilidad con datos antiguos donde el egg era una lista.
        if (is_array($egg)) {
            $egg = $egg[0] ?? null;
        }

        return is_string($egg) ? $egg : null;
    }

    public function getPterodactylVersionAttribute(): ?string
    {
        $version = $this->pterodactylConfig?->version ?? ($this->provisioner_config['version'] ?? null);

        return is_string($version) ? $version : null;
    }

    public function getPterodactylDockerImageAttribute(): ?string
    {
        return $this->pterodactylConfig?->docker_image
            ?? ($this->attributes['pterodactyl_docker_image'] ?? null);
    }

    public function getPterodactylStartupAttribute(): ?string
    {
        return $this->pterodactylConfig?->startup
            ?? ($this->attributes['pterodactyl_startup'] ?? null);
    }

    public function getPterodactylNodeIdAttribute(): ?int
    {
        $value = $this->pterodactylConfig?->node_id ?? ($this->attributes['pterodactyl_node_id'] ?? null);

        return $value !== null ? (int) $value : null;
    }

    public function getMaxPlayersAttribute(): ?int
    {
        $value = $this->pterodactylConfig?->max_players ?? ($this->attributes['max_players'] ?? null);

        return $value !== null ? (int) $value : null;
    }

    public function getGameTypeAttribute(): ?string
    {
        return $this->pterodactylConfig?->game_type
            ?? ($this->attributes['game_type'] ?? null);
    }

    public function getPterodactylEnvironmentAttribute(): ?array
    {
        $env = $this->pterodactylConfig?->environment ?? $this->legacyJson('pterodactyl_environment');

        return empty($env) ? null : $env;
    }

    public function getPterodactylLimitsAttribute(): ?array
    {
        return $this->pterodactylConfig?->limits ?? $this->legacyJson('pterodactyl_limits');
    }

    public function getPterodactylFeatureLimitsAttribute(): ?array
    {
        return $this->pterodactylConfig?->feature_limits ?? $this->legacyJson('pterodactyl_feature_limits');
    }

    public function getAllowedNestIdsAttribute(): ?array
    {
        return $this->pterodactylConfig?->allowed_nest_ids ?? $this->legacyJson('allowed_nest_ids');
    }

    public function getGameRuntimeOptionsAttribute(): ?array
    {
        return $this->pterodactylConfig?->game_runtime_options ?? $this->legacyJson('game_runtime_options');
    }

    public function getGameConfigSchemaAttribute(): ?array
    {
        return $this->pterodactylConfig?->game_config_schema ?? $this->legacyJson('game_config_schema');
    }

    public function getCoolifyBuildPackAttribute(): ?string
    {
        $buildPack = $this->coolifyConfig?->build_pack ?? ($this->provisioner_config['build_pack'] ?? null);

        return is_string($buildPack) ? $buildPack : null;
    }

    public function getCoolifyDbEnabledAttribute(): ?bool
    {
        if ($this->coolifyConfig) {
            return (bool) $this->coolifyConfig->db_enabled;
        }

        return array_key_exists('db_enabled', $this->provisioner_config ?? [])
            ? (bool) $this->provisioner_config['db_enabled']
            : null;
    }

    private function decodeJsonAttribute(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Devuelve los límites reales de Pterodactyl para este plan.
     *
     * Orden de prioridad:
     *   1. pterodactyl_limits  (columna explícita en DB — lo ideal)
     *   2. Derivado de specifications (conversión automática de specs humanas)
     *   3. Defaults del config ('pterodactyl.defaults.limits')
     *
     * Esto actúa como red de seguridad: si un plan se crea sin pterodactyl_limits,
     * el aprovisionador siempre recibe valores razonables en lugar del mínimo global.
     */
    public function resolvedLimits(): array
    {
        if (! empty($this->pterodactyl_limits)) {
            return $this->pterodactyl_limits;
        }

        $derived = $this->limitsFromSpecifications();
        if (! empty($derived)) {
            return $derived;
        }

        return config('pterodactyl.defaults.limits');
    }

    /**
     * Devuelve los feature_limits reales para este plan.
     * Misma lógica que resolvedLimits().
     */
    public function resolvedFeatureLimits(): array
    {
        if (! empty($this->pterodactyl_feature_limits)) {
            return $this->pterodactyl_feature_limits;
        }

        return config('pterodactyl.defaults.feature_limits');
    }

    /**
     * Convierte el campo `specifications` (formato legible por humanos) a límites
     * de Pterodactyl en MB/%.
     *
     * Soporta formatos como "8 GB RAM", "100 GB SSD", "4 vCPU".
     * Devuelve [] si no puede parsear nada útil.
     */
    private function limitsFromSpecifications(): array
    {
        $specs = $this->specifications ?? [];
        if (empty($specs)) {
            return [];
        }

        $limits = config('pterodactyl.defaults.limits'); // base

        // RAM: "2 GB RAM", "512 MB RAM"
        foreach (['ram', 'memory', 'ram_gb'] as $key) {
            if (isset($specs[$key])) {
                $mb = $this->parseToMb((string) $specs[$key]);
                if ($mb > 0) {
                    $limits['memory'] = $mb;
                    break;
                }
            }
        }

        // Disco: "25 GB SSD", "100 GB NVMe"
        foreach (['storage', 'disk', 'storage_gb'] as $key) {
            if (isset($specs[$key])) {
                $mb = $this->parseToMb((string) $specs[$key]);
                if ($mb > 0) {
                    $limits['disk'] = $mb;
                    break;
                }
            }
        }

        // CPU: "2 vCPU", "4 cores"  → 100% por vCPU
        foreach (['cpu', 'vcpu', 'cores'] as $key) {
            if (isset($specs[$key])) {
                if (preg_match('/(\d+(\.\d+)?)\s*(vcpu|vcore|core|cpu)/i', (string) $specs[$key], $m)) {
                    $limits['cpu'] = (int) round((float) $m[1] * 100);
                    break;
                }
            }
        }

        return $limits;
    }

    /**
     * Parsea una cadena como "8 GB", "512 MB", "100 GB SSD" a MB enteros.
     */
    private function parseToMb(string $value): int
    {
        if (preg_match('/(\d+(\.\d+)?)\s*(GB|GiB)/i', $value, $m)) {
            return (int) round((float) $m[1] * 1024);
        }
        if (preg_match('/(\d+(\.\d+)?)\s*(MB|MiB)/i', $value, $m)) {
            return (int) round((float) $m[1]);
        }
        return 0;
    }

    public function addOns()
    {
        return $this->belongsToMany(AddOn::class, 'add_on_plan')
            ->using(AddOnPlan::class)
            ->withPivot('uuid', 'is_default')
            ->withTimestamps();
    }
}
