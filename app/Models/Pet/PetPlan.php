<?php

namespace App\Models\Pet;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PetPlan extends Model
{
    use HasUuids;

    protected $connection = 'roke_pet';
    protected $table      = 'pet_plans';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'stripe_product_id',
        'price_monthly',
        'price_yearly',
        'trial_enabled',
        'trial_days',
        'max_pets',
        'features',
        'stripe_price_monthly',
        'stripe_price_yearly',
        'is_active',
        'sort_order',
        'highlighted',
        'audience',
        'badge',
        'cta_label',
        'checkout_url',
        'metadata',
    ];

    protected $casts = [
        'price_monthly'  => 'float',
        'price_yearly'   => 'float',
        'trial_enabled'  => 'boolean',
        'trial_days'     => 'integer',
        'max_pets'       => 'integer',
        'features'       => 'array',
        'is_active'      => 'boolean',
        'sort_order'     => 'integer',
        'highlighted'    => 'boolean',
        'metadata'       => 'array',
    ];

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeOrdered(Builder $q): Builder
    {
        return $q->orderBy('sort_order')->orderBy('price_monthly');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function stripePrice(string $billing = 'monthly'): ?string
    {
        return $billing === 'yearly'
            ? $this->stripe_price_yearly
            : $this->stripe_price_monthly;
    }

    public function trialDays(): int
    {
        return $this->trial_enabled ? $this->trial_days : 0;
    }
}
