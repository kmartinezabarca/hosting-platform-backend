<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CheckoutQuote extends Model
{
    protected $fillable = [
        'uuid',
        'user_id',
        'service_plan_id',
        'billing_cycle_id',
        'selected_add_on_ids',
        'request_payload',
        'pricing_snapshot',
        'quote_hash',
        'currency',
        'subtotal',
        'discount',
        'tax',
        'total',
        'is_free',
        'is_trial',
        'trial_days',
        'expires_at',
        'consumed_at',
    ];

    protected $casts = [
        'selected_add_on_ids' => 'array',
        'request_payload'     => 'array',
        'pricing_snapshot'    => 'array',
        'subtotal'            => 'decimal:2',
        'discount'            => 'decimal:2',
        'tax'                 => 'decimal:2',
        'total'               => 'decimal:2',
        'is_free'             => 'boolean',
        'is_trial'            => 'boolean',
        'trial_days'          => 'integer',
        'expires_at'          => 'datetime',
        'consumed_at'         => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $quote): void {
            $quote->uuid ??= (string) Str::uuid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function servicePlan(): BelongsTo
    {
        return $this->belongsTo(ServicePlan::class);
    }

    public function billingCycle(): BelongsTo
    {
        return $this->belongsTo(BillingCycle::class);
    }
}
