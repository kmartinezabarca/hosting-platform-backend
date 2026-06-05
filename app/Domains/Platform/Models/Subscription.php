<?php

namespace App\Domains\Platform\Models;
use App\Models\User;

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
        'cancel_at_period_end',
        'amount',
        'currency',
        'billing_cycle',
        'current_period_start',
        'current_period_end',
        'trial_start',
        'trial_end',
        'canceled_at',
        'ends_at',
        'payment_failed_at',
        'grace_period_ends_at',
        'next_payment_attempt',
        'last_payment_error',
        'suspended_at',
        'suspension_reason',
        'metadata'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'cancel_at_period_end' => 'boolean',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'trial_start' => 'datetime',
        'trial_end' => 'datetime',
        'canceled_at' => 'datetime',
        'ends_at' => 'datetime',
        'payment_failed_at' => 'datetime',
        'grace_period_ends_at' => 'datetime',
        'next_payment_attempt' => 'datetime',
        'suspended_at' => 'datetime',
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
     * ¿La suscripción está programada para cancelarse al fin del periodo?
     */
    public function isScheduledToCancel(): bool
    {
        return (bool) $this->cancel_at_period_end
            && in_array($this->status, ['active', 'trialing', 'past_due'], true);
    }

    /**
     * ¿Puede reactivarse quitando la cancelación programada?
     * (Aún no expira el periodo y no está cancelada de forma definitiva.)
     */
    public function canResumeBeforePeriodEnd(): bool
    {
        return $this->isScheduledToCancel()
            && $this->current_period_end
            && $this->current_period_end->isFuture();
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

