<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlanFeature extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'service_plan_id',
        'feature',
        'is_highlighted',
        'sort_order',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_highlighted' => 'boolean',
    ];

    /**
     * Get the service plan that owns the feature.
     */
    public function servicePlan()
    {
        return $this->belongsTo(ServicePlan::class);
    }

    /**
     * Scope a query to only include highlighted features.
     */
    public function scopeHighlighted($query)
    {
        return $query->where('is_highlighted', true);
    }
}

