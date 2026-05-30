<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro de idempotencia de eventos de webhook de Stripe.
 *
 * @see \App\Http\Controllers\Common\StripeWebhookController
 */
class StripeEvent extends Model
{
    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROCESSED  = 'processed';
    public const STATUS_FAILED     = 'failed';

    protected $fillable = [
        'event_id',
        'type',
        'status',
        'attempts',
        'payload',
        'error',
        'processed_at',
    ];

    protected $casts = [
        'payload'      => 'array',
        'attempts'     => 'integer',
        'processed_at' => 'datetime',
    ];
}
