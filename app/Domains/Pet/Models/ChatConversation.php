<?php

namespace App\Domains\Pet\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conversación del chat de soporte unificado (brand-aware).
 *
 * Reusa la arquitectura en tiempo real del chat de tickets de ROKE Industries
 * (Reverb + eventos por canal privado), pero vive en la BD `roke_pet` porque la
 * identidad del cliente Pet (Owner) está en otra conexión. Ver [[roke-chat-architecture]].
 */
class ChatConversation extends Model
{
    use HasUuids;

    protected $connection = 'roke_pet';
    protected $table      = 'pet_chat_conversations';

    public const STATUS_OPEN             = 'open';
    public const STATUS_AI_ACTIVE        = 'ai_active';
    public const STATUS_WAITING_CUSTOMER = 'waiting_customer';
    public const STATUS_WAITING_AGENT    = 'waiting_agent';
    public const STATUS_HUMAN_ACTIVE     = 'human_active';
    public const STATUS_RESOLVED         = 'resolved';
    public const STATUS_CLOSED           = 'closed';

    public const ACTIVE_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_AI_ACTIVE,
        self::STATUS_WAITING_CUSTOMER,
        self::STATUS_WAITING_AGENT,
        self::STATUS_HUMAN_ACTIVE,
    ];

    protected $fillable = [
        'brand', 'channel', 'source',
        'owner_id', 'assigned_agent_id',
        'status', 'priority', 'subject',
        'ai_enabled', 'ai_status',
        'unread_for_owner', 'unread_for_agent',
        'last_message_at', 'escalated_at', 'escalation_reason',
        'resolved_at', 'closed_at', 'metadata',
    ];

    protected $casts = [
        'ai_enabled'       => 'boolean',
        'unread_for_owner' => 'integer',
        'unread_for_agent' => 'integer',
        'last_message_at'  => 'datetime',
        'escalated_at'     => 'datetime',
        'resolved_at'      => 'datetime',
        'closed_at'        => 'datetime',
        'metadata'         => 'array',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
    ];

    /* ===================== Relaciones ===================== */

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id')->orderBy('created_at');
    }

    public function latestMessage(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id')->latest('created_at');
    }

    public function owner()
    {
        // owner_id guarda el uuid del usuario (== owners.id, la PK del Owner).
        return $this->belongsTo(Owner::class, 'owner_id', 'id');
    }

    /* ===================== Scopes ===================== */

    public function scopeForBrand($q, string $brand = 'roke_pet')
    {
        return $q->where('brand', $brand);
    }

    public function scopeActive($q)
    {
        return $q->whereIn('status', self::ACTIVE_STATUSES);
    }

    public function scopeForOwner($q, string $ownerId)
    {
        return $q->where('owner_id', $ownerId);
    }

    /* ===================== Helpers de estado ===================== */

    public function isClosed(): bool
    {
        return in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_CLOSED], true);
    }

    public function isHumanControlled(): bool
    {
        return $this->status === self::STATUS_HUMAN_ACTIVE || $this->assigned_agent_id !== null;
    }

    /**
     * ¿La IA debe responder automáticamente al próximo mensaje del cliente?
     * Sólo cuando la IA está habilitada y la conversación sigue en modo IA.
     * En cuanto un humano toma la conversación (human_active) o se pide escalar
     * (waiting_agent), la IA deja de auto-responder.
     */
    public function aiShouldAutoReply(): bool
    {
        return $this->ai_enabled
            && $this->ai_status === 'enabled'
            && $this->status === self::STATUS_AI_ACTIVE;
    }

    public function broadcastChannelName(): string
    {
        return 'rp-chat.' . $this->id;
    }
}
