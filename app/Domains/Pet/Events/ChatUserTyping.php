<?php

namespace App\Domains\Pet\Events;

use App\Domains\Pet\Models\ChatConversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Señal "escribiendo…" en una conversación (dueño, agente o la IA). */
class ChatUserTyping implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly ChatConversation $conversation,
        public readonly string $senderType,   // pet_owner | agent | ai
        public readonly ?string $senderName,
        public readonly bool $isTyping = true,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('rp-chat.' . $this->conversation->id)];
    }

    public function broadcastAs(): string
    {
        return 'chat.typing';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversation->id,
            'sender_type'     => $this->senderType,
            'sender_name'     => $this->senderName,
            'is_typing'       => $this->isTyping,
        ];
    }
}
