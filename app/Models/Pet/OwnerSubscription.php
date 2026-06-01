<?php

namespace App\Models\Pet;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OwnerSubscription extends Model
{
    use HasUuids;

    protected $connection = 'roke_pet';
    protected $table = 'owner_subscriptions';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'owner_id', 'plan_code', 'status', 'cancel_at_period_end', 'provider', 'checkout_url', 'billing_email',
        'trial_ends_at', 'current_period_end', 'support_notes',
        'stripe_customer_id', 'stripe_subscription_id', 'stripe_checkout_session_id',
        'stripe_price_id', 'last_invoice_id', 'canceled_at',
        'payment_failed_at', 'grace_period_ends_at', 'last_payment_error', 'expiry_notified_at',
    ];

    protected $casts = [
        'cancel_at_period_end' => 'boolean',
        'trial_ends_at'        => 'datetime',
        'current_period_end'   => 'datetime',
        'canceled_at'          => 'datetime',
        'payment_failed_at'    => 'datetime',
        'grace_period_ends_at' => 'datetime',
        'expiry_notified_at'   => 'datetime',
    ];

    /** Días de gracia tras un cobro fallido antes de degradar al plan gratuito. */
    public const GRACE_PERIOD_DAYS = 5;

    /** Slug del plan gratuito al que se degrada la cuenta tras la morosidad. */
    public const FREE_PLAN_CODE = 'free';

    protected $attributes = [
        'plan_code' => 'starter',
        'status'    => 'trialing',
        'provider'  => 'stripe_payment_link',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'owner_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PetPlan::class, 'plan_code', 'slug');
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['active', 'trialing']);
    }

    /** ¿Está en periodo de gracia por un cobro fallido todavía no vencido? */
    public function inGracePeriod(): bool
    {
        return $this->status === 'past_due'
            && $this->grace_period_ends_at !== null
            && $this->grace_period_ends_at->isFuture();
    }
}
