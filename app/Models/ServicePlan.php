<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ServicePlan extends Model
{
    use HasFactory;

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
        'game_runtime_options'        => 'array',
        'game_config_schema'          => 'array',
        'pterodactyl_limits'         => 'array',
        'pterodactyl_feature_limits' => 'array',
        'pterodactyl_environment'    => 'array',
        'allowed_nest_ids'           => 'array',
        'max_players'                => 'integer',
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

    public function isPterodactylManaged(): bool
    {
        return $this->provisioner === 'pterodactyl';
    }

    public function isGameServerPlan(): bool
    {
        return $this->provisioner === 'pterodactyl' && !empty($this->game_type);
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
