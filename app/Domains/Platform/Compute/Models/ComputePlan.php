<?php

namespace App\Domains\Platform\Compute\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ComputePlan extends Model
{
    protected $table = 'compute_plan_catalog_entries';

    protected $fillable = [
        'kind',
        'tier',
        'name',
        'description',
        'sort_order',
        'currency',
        'stripe_product_id',
        'monthly_amount',
        'annual_amount',
        'stripe_monthly_price_id',
        'stripe_annual_price_id',
        'max_resources',
        'ram_mb_max',
        'max_members',
        'features',
        'is_active',
    ];

    protected $casts = [
        'monthly_amount' => 'decimal:2',
        'annual_amount' => 'decimal:2',
        'max_resources' => 'integer',
        'ram_mb_max' => 'integer',
        'max_members' => 'integer',
        'features' => 'array',
        'is_active' => 'boolean',
    ];

    public function scopeCompute(Builder $query): Builder
    {
        return $query->where('kind', 'compute');
    }
}
