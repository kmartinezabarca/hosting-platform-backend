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
    ];

    protected $casts = [
        'cancel_at_period_end' => 'boolean',
        'trial_ends_at'        => 'datetime',
        'current_period_end'   => 'datetime',
        'canceled_at'          => 'datetime',
    ];

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
}
