<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BillingCycle extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'uuid',
        'slug',
        'name',
        'months',
        'discount_percentage',
        'is_active',
        'sort_order',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'discount_percentage' => 'decimal:2',
        'is_active' => 'boolean',
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
     * Scope a query to only include active billing cycles.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get the plan pricing for this billing cycle.
     */
    public function planPricing()
    {
        return $this->hasMany(PlanPricing::class);
    }

    /**
     * Calculate discounted price from base price.
     */
    public function calculateDiscountedPrice($basePrice)
    {
        $discount = $this->discount_percentage / 100;
        return $basePrice * (1 - $discount);
    }

    /**
     * Get discount amount from base price.
     */
    public function getDiscountAmount($basePrice)
    {
        return $basePrice * ($this->discount_percentage / 100);
    }
}

