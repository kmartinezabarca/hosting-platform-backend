<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'user_id',
        'service_id',
        'stripe_subscription_id',
        'stripe_customer_id',
        'stripe_price_id',
        'name',
        'status',
        'amount',
        'currency',
        'billing_cycle',
        'current_period_start',
        'current_period_end',
        'trial_start',
        'trial_end',
        'canceled_at',
        'ends_at',
        'metadata'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'trial_start' => 'datetime',
        'trial_end' => 'datetime',
        'canceled_at' => 'datetime',
        'ends_at' => 'datetime',
        'metadata' => 'array'
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($subscription) {
            if (empty($subscription->uuid)) {
                $subscription->uuid = Str::uuid();
            }
        });
    }

    /**
     * Get the user that owns the subscription
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the service associated with the subscription
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Check if subscription is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if subscription is canceled
     */
    public function isCanceled(): bool
    {
        return $this->status === 'canceled';
    }

    /**
     * Check if subscription is past due
     */
    public function isPastDue(): bool
    {
        return $this->status === 'past_due';
    }

    /**
     * Check if subscription is in trial period
     */
    public function isTrialing(): bool
    {
        return $this->status === 'trialing';
    }

    /**
     * Get days until next billing
     */
    public function daysUntilNextBilling(): int
    {
        if (!$this->current_period_end) {
            return 0;
        }
        
        return now()->diffInDays($this->current_period_end, false);
    }

    /**
     * Scope for active subscriptions
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope for canceled subscriptions
     */
    public function scopeCanceled($query)
    {
        return $query->where('status', 'canceled');
    }

    /**
     * Scope for past due subscriptions
     */
    public function scopePastDue($query)
    {
        return $query->where('status', 'past_due');
    }
}

