<?php

namespace App\Domains\Pet\Events;

use App\Domains\Pet\Models\ChatConversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** La conversación se resolvió o cerró. */
class ChatConversationResolved implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly ChatConversation $conversation,
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
        return 'chat.resolved';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversation->id,
            'status'          => $this->conversation->status,
            'resolved_at'     => $this->conversation->resolved_at?->toISOString(),
            'closed_at'       => $this->conversation->closed_at?->toISOString(),
        ];
    }
}
