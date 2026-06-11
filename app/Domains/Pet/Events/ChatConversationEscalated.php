<?php

namespace App\Domains\Pet\Events;

use App\Domains\Pet\Models\ChatConversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * La conversación se escaló a soporte humano (por petición del cliente, baja
 * confianza de la IA o caso sensible). Avisa al panel admin para atenderla.
 */
class ChatConversationEscalated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly ChatConversation $conversation,
        public readonly string $reason,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('rp-chat.' . $this->conversation->id),
            new PrivateChannel('rp-admin.chat'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat.escalated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversation->id,
            'reason'          => $this->reason,
            'status'          => $this->conversation->status,
            'escalated_at'    => $this->conversation->escalated_at?->toISOString(),
        ];
    }
}
