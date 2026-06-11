<?php

namespace App\Domains\Pet\Events;

use App\Domains\Pet\Models\ChatConversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Un agente humano tomó la conversación. El frontend muestra el aviso claro
 * "Un agente de soporte se unió a la conversación" y deja de mostrar a la IA.
 */
class ChatAgentJoined implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly ChatConversation $conversation,
        public readonly string $agentName,
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
        return 'chat.agent_joined';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'conversation_id'   => $this->conversation->id,
            'agent_name'        => $this->agentName,
            'assigned_agent_id' => $this->conversation->assigned_agent_id,
            'status'            => $this->conversation->status,
            'ai_enabled'        => (bool) $this->conversation->ai_enabled,
        ];
    }
}
