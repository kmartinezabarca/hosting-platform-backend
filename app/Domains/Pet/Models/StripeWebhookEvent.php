<?php

namespace App\Domains\Pet\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StripeWebhookEvent extends Model
{
    use HasUuids;

    protected $connection = 'roke_pet';
    protected $table = 'stripe_webhook_events';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'event_id', 'event_type', 'owner_id', 'stripe_customer_id',
        'stripe_subscription_id', 'payload', 'processed_at',
    ];

    protected $casts = [
        'payload'      => 'array',
        'processed_at' => 'datetime',
    ];
}
