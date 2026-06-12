<?php

namespace App\Domains\Pet\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    use HasUuids;

    protected $connection = 'roke_pet';
    protected $table      = 'pet_chat_messages';

    public const SENDER_OWNER  = 'pet_owner';
    public const SENDER_AGENT  = 'agent';
    public const SENDER_AI     = 'ai';
    public const SENDER_SYSTEM = 'system';

    public const TYPE_TEXT        = 'text';
    public const TYPE_SYSTEM      = 'system';
    public const TYPE_QUICK_REPLY = 'quick_reply';
    public const TYPE_ATTACHMENT  = 'attachment';

    protected $fillable = [
        'conversation_id', 'sender_type', 'sender_id', 'sender_name',
        'body', 'message_type', 'delivered_at', 'read_at',
        'ai_confidence', 'ai_sources', 'metadata',
    ];

    protected $casts = [
        'delivered_at'  => 'datetime',
        'read_at'       => 'datetime',
        'ai_confidence' => 'float',
        'ai_sources'    => 'array',
        'metadata'      => 'array',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function isFromCustomer(): bool
    {
        return $this->sender_type === self::SENDER_OWNER;
    }

    public function isFromStaff(): bool
    {
        return in_array($this->sender_type, [self::SENDER_AGENT, self::SENDER_AI], true);
    }

    /** Forma serializable que consume el frontend (idéntica en API y broadcast). */
    public function toBroadcastArray(): array
    {
        $metadata = $this->metadata ?? [];

        return [
            'id'              => $this->id,
            'conversation_id' => $this->conversation_id,
            'sender_type'     => $this->sender_type,
            'sender_id'       => $this->sender_id,
            'sender_name'     => $this->sender_name,
            'body'            => $this->body,
            'message_type'    => $this->message_type,
            'ai_confidence'   => $this->ai_confidence,
            'ai_sources'      => $this->ai_sources,
            'metadata'        => $metadata ?: null,
            'attachments'     => $metadata['attachments'] ?? [],
            'delivered_at'    => $this->delivered_at?->toISOString(),
            'read_at'         => $this->read_at?->toISOString(),
            'created_at'      => $this->created_at?->toISOString(),
        ];
    }
}
