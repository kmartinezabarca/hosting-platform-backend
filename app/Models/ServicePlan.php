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
        'is_popular',
        'is_active',
        'sort_order',
        'specifications',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'base_price' => 'decimal:2',
        'setup_fee' => 'decimal:2',
        'is_popular' => 'boolean',
        'is_active' => 'boolean',
        'specifications' => 'array',
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
        return $this->hasMany(Service::class, 'plan_id', 'slug');
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

    public function addOns()
    {
        return $this->belongsToMany(AddOn::class, 'add_on_plan')
            ->withPivot('is_default')->withTimestamps();
    }
}
