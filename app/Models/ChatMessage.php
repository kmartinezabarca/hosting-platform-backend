<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'chat_room_id',
        'user_id',
        'message',
        'type',
        'attachment_url',
        'is_from_admin',
        'read_at',
    ];

    protected $casts = [
        'is_from_admin' => 'boolean',
        'read_at' => 'datetime',
    ];

    /**
     * Get the chat room that owns the message.
     */
    public function chatRoom(): BelongsTo
    {
        return $this->belongsTo(ChatRoom::class);
    }

    /**
     * Get the user that sent the message.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope for unread messages.
     */
    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    /**
     * Scope for admin messages.
     */
    public function scopeFromAdmin($query)
    {
        return $query->where('is_from_admin', true);
    }

    /**
     * Scope for client messages.
     */
    public function scopeFromClient($query)
    {
        return $query->where('is_from_admin', false);
    }

    /**
     * Mark message as read.
     */
    public function markAsRead(): void
    {
        $this->update(['read_at' => now()]);
    }

    /**
     * Check if message is read.
     */
    public function isRead(): bool
    {
        return !is_null($this->read_at);
    }
}

