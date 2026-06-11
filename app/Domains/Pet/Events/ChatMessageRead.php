<?php

namespace App\Domains\Pet\Events;

use App\Domains\Pet\Models\ChatConversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Acuses de lectura en vivo (✓✓). `reader` indica quién leyó: owner | agent. */
class ChatMessageRead implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @param array<int, string> $messageIds */
    public function __construct(
        public readonly ChatConversation $conversation,
        public readonly array $messageIds,
        public readonly string $reader,   // owner | agent
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('rp-chat.' . $this->conversation->id)];
    }

    public function broadcastAs(): string
    {
        return 'chat.read';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversation->id,
            'message_ids'     => $this->messageIds,
            'reader'          => $this->reader,
            'read_at'         => now()->toISOString(),
        ];
    }
}
