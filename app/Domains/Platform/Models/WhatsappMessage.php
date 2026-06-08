<?php

namespace App\Domains\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un mensaje dentro de un hilo de WhatsApp (entrante o saliente).
 */
class WhatsappMessage extends Model
{
    public const DIRECTION_INBOUND  = 'inbound';
    public const DIRECTION_OUTBOUND = 'outbound';

    public const SENDER_CONTACT = 'contact';
    public const SENDER_BOT     = 'bot';
    public const SENDER_AGENT   = 'agent';

    protected $fillable = [
        'conversation_id',
        'direction',
        'sender',
        'body',
        'wa_message_id',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsappConversation::class, 'conversation_id');
    }
}
