<?php

namespace App\Domains\Pet\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un envío concreto de una notificación de engagement a una audiencia (hoy:
 * todos los dueños). Guarda un snapshot del contenido y los contadores de
 * entrega que va llenando SendCampaignJob.
 */
class NotificationCampaign extends Model
{
    use HasUuids;

    protected $connection = 'roke_pet';
    protected $table      = 'pet_notification_campaigns';

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_SENDING   = 'sending';
    public const STATUS_SENT      = 'sent';
    public const STATUS_CANCELED  = 'canceled';
    public const STATUS_FAILED    = 'failed';

    protected $fillable = [
        'tip_id', 'title', 'body', 'category', 'url', 'icon',
        'audience', 'audience_filters',
        'status', 'scheduled_at', 'started_at', 'finished_at',
        'recipients_total', 'sent_count', 'failed_count',
        'created_by',
    ];

    protected $casts = [
        'audience_filters' => 'array',
        'scheduled_at'     => 'datetime',
        'started_at'       => 'datetime',
        'finished_at'      => 'datetime',
        'recipients_total' => 'integer',
        'sent_count'       => 'integer',
        'failed_count'     => 'integer',
    ];

    public function tip(): BelongsTo
    {
        return $this->belongsTo(NotificationTip::class, 'tip_id');
    }

    /** ¿Sigue pendiente de procesar (programada o a punto de enviarse)? */
    public function isDispatchable(): bool
    {
        return in_array($this->status, [self::STATUS_SCHEDULED, self::STATUS_SENDING], true);
    }

    public function scopeDue($q)
    {
        return $q->where('status', self::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now());
    }
}
