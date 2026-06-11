<?php

namespace App\Domains\Pet\Events;

use App\Domains\Pet\Models\ChatConversation;
use App\Domains\Pet\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Un mensaje nuevo en una conversación de soporte (de dueño, agente, IA o sistema).
 *
 * Cubre tanto `MessageSent` como `AiResponseCreated` de la especificación: un
 * mensaje de IA viaja por este mismo evento con sender_type = 'ai', de modo que
 * el frontend tiene un único punto de render para mensajes entrantes.
 *
 * Se emite por el canal privado de la conversación (dueño + agentes) y por el
 * feed de administración (lista de conversaciones en vivo).
 */
class ChatMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly ChatConversation $conversation,
        public readonly ChatMessage $message,
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
        return 'chat.message';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'message'      => $this->message->toBroadcastArray(),
            'conversation' => [
                'id'         => $this->conversation->id,
                'status'     => $this->conversation->status,
                'ai_enabled' => (bool) $this->conversation->ai_enabled,
                'ai_status'  => $this->conversation->ai_status,
            ],
        ];
    }
}
